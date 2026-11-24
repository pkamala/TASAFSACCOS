<?php

namespace App;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ActiveLoanMigration
{
    public const Headers = ['legacy_loan_id', 'due_date', 'principal_tzs', 'interest_tzs'];

    public function __construct(private LedgerPeriods $periods) {}

    public function read(UploadedFile $upload, string $cutover): array
    {
        $file = ImportTable::open($upload, 'schedule');
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv() ?: [];
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
        if ($header !== self::Headers) {
            $this->invalid('Use the columns from the active loan migration template.');
        }

        $schedules = [];
        $line = 1;
        $rowCount = 0;
        while (! $file->eof()) {
            $values = $file->fgetcsv();
            $line++;
            if ($values === false || $values === [null]) {
                continue;
            }
            if (count($values) !== count(self::Headers) || ++$rowCount > 6000) {
                $this->invalid("Invalid column count or 6,000-installment limit exceeded at row $line.");
            }

            [$id, $due, $principal, $interest] = array_map('trim', $values);
            if (! preg_match('/\A[1-9]\d{0,9}\z/', $id) || (int) $id > 2147483647
                || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $due, $date)
                || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])
                || $due < $cutover || $due > '2089-12-31') {
                $this->invalid("Enter a legacy loan ID and a valid due date on or after cutover at row $line.");
            }

            $id = (int) $id;
            $principalMinor = $this->minor($principal, $line);
            $interestMinor = $this->minor($interest, $line);
            if ($principalMinor + $interestMinor === 0 || isset($schedules[$id][$due]) || count($schedules[$id] ?? []) >= 120) {
                $this->invalid("Use positive installment totals and at most 120 distinct due dates per loan. Check row $line.");
            }

            $schedules[$id][$due] = ['due_date' => $due, 'principal_minor' => $principalMinor, 'interest_minor' => $interestMinor];
        }

        if ($schedules === []) {
            $this->invalid('The file has no remaining installments.');
        }

        return array_map(static function (array $installments): array {
            ksort($installments);

            return array_values($installments);
        }, $schedules);
    }

    public function plan(string $snapshot, string $cutover, array $schedules): array
    {
        $asOf = CarbonImmutable::parse($cutover)->subDay()->toDateString();
        $balances = DB::table('verified_legacy_loan_balances as balance')->where('balance.source_sha256', $snapshot)
            ->where('balance.as_of', $asOf)->whereIntegerInRaw('balance.source_id', array_keys($schedules))
            ->join('members as member', 'member.id', '=', 'balance.member_id')
            ->select(['balance.id', 'balance.source_id', 'balance.member_id', 'balance.principal_minor', 'member.registration_no', 'member.status'])
            ->get()->keyBy('source_id');
        $migrated = DB::table('active_loan_migrations')->where('source_sha256', $snapshot)
            ->whereIntegerInRaw('source_id', array_keys($schedules))->pluck('loan_application_id', 'source_id');

        $plan = [];
        foreach ($schedules as $sourceId => $installments) {
            $balance = $balances->get($sourceId);
            $principal = array_sum(array_column($installments, 'principal_minor'));
            $plan[] = [
                'source_id' => $sourceId, 'verified_id' => $balance?->id, 'member_id' => $balance?->member_id,
                'registration_no' => $balance?->registration_no, 'principal_minor' => $principal,
                'verified_minor' => $balance?->principal_minor, 'interest_minor' => array_sum(array_column($installments, 'interest_minor')),
                'installment_count' => count($installments), 'first_due_date' => $installments[0]['due_date'],
                'status' => match (true) {
                    $migrated->has($sourceId) => 'Already migrated',
                    $balance === null => 'No verified principal for the day before cutover',
                    $balance->status !== 'active' => 'Member is inactive',
                    $principal === 0 || $principal !== (int) $balance->principal_minor => 'Scheduled principal differs from verified principal or is zero',
                    default => 'Ready',
                },
            ];
        }

        return $plan;
    }

    public function apply(array $preview, int $actor): int
    {
        return DB::transaction(function () use ($preview, $actor): int {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['active-loan-migration']);
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['payroll-schedule']);
            $cutover = $preview['cutover'];
            if (DB::table('ledger_opening_journals')->value('effective_on') !== $cutover) {
                $this->invalid('Run a new preview for the posted ledger cutover date.');
            }
            DB::table('members')->whereIntegerInRaw('id', array_filter(array_column($preview['plan'], 'member_id')))
                ->orderBy('id')->lockForUpdate()->get(['id']);
            $plan = $this->plan($preview['snapshot'], $cutover, $preview['schedules']);
            if ($plan !== $preview['plan'] || collect($plan)->contains(fn (array $row): bool => $row['status'] !== 'Ready')) {
                $this->invalid('Resolve the exceptions or run a fresh preview before applying.');
            }

            $dates = collect($preview['schedules'])->flatten(1)->pluck('due_date')->push($cutover)->unique()->all();
            $months = array_values(array_unique(array_map(fn (string $date): string => substr($date, 0, 7).'-01', $dates)));
            sort($months);
            foreach ($months as $month) {
                DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['payroll:'.$month]);
            }
            $this->periods->requireOpenDates($dates, 'schedule');
            if (DB::table('loan_batches')->where('kind', 'repayment')->whereIn('period', $months)
                ->whereIn('status', ['prepared', 'approved', 'confirmed'])->exists()) {
                $this->invalid('An affected payroll month already has a prepared, approved, or confirmed list.');
            }

            foreach ($plan as $row) {
                $loan = DB::table('loan_applications')->insertGetId([
                    'submission_id' => (string) Str::uuid(), 'member_id' => $row['member_id'], 'created_by' => $actor,
                    'principal_minor' => $row['principal_minor'], 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
                    'annual_basis_points' => 0, 'term_months' => $row['installment_count'], 'interest_method' => 'scheduled',
                    'first_due_date' => $row['first_due_date'], 'status' => 'disbursed', 'created_at' => now(),
                ]);
                DB::table('active_loan_migrations')->insert([
                    'loan_application_id' => $loan, 'verified_balance_id' => $row['verified_id'],
                    'source_sha256' => $preview['snapshot'], 'source_id' => $row['source_id'],
                    'effective_on' => $cutover, 'schedule_sha256' => $preview['sha256'], 'review_reference' => $preview['reference'],
                ]);

                $balance = $row['principal_minor'];
                $installments = [];
                foreach ($preview['schedules'][$row['source_id']] as $index => $entry) {
                    $installments[] = [
                        ...$entry, 'loan_application_id' => $loan, 'number' => $index + 1,
                        'opening_minor' => $balance, 'payment_minor' => $entry['principal_minor'] + $entry['interest_minor'],
                        'closing_minor' => $balance - $entry['principal_minor'],
                    ];
                    $balance -= $entry['principal_minor'];
                }
                DB::table('loan_application_installments')->insert($installments);
            }

            return count($plan);
        }, 3);
    }

    private function minor(string $amount, int $line): int
    {
        if (! preg_match('/\A\d{1,12}(?:\.\d{1,2})?\z/', $amount)) {
            $this->invalid("Enter non-negative TZS amounts with up to two decimals at row $line.");
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['schedule' => $message]);
    }
}
