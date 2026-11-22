<?php

namespace App;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerOpeningJournal
{
    private const Headers = ['legacy_account_id', 'account_code', 'debit_tzs', 'credit_tzs'];

    public function __construct(private LedgerPeriods $periods) {}

    public function read(UploadedFile $upload): array
    {
        $file = $upload->openFile();
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv() ?: [];
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
        if ($header !== self::Headers) {
            $this->invalid('Use the headers from the ledger opening journal template.');
        }

        $rows = [];
        $line = 1;
        while (! $file->eof()) {
            $values = $file->fgetcsv();
            $line++;
            if ($values === false || $values === [null]) {
                continue;
            }
            if (count($values) !== 4 || count($rows) >= 1000) {
                $this->invalid("Invalid column count or 1,000-row limit exceeded at CSV record $line.");
            }

            [$id, $code, $debit, $credit] = array_map('trim', $values);
            if (! preg_match('/\A[1-9]\d{0,9}\z/', $id) || (int) $id > 2147483647 || isset($rows[(int) $id])
                || preg_match('/\A[\x20-\x7e]{1,100}\z/', $code) !== 1) {
                $this->invalid("Supply a unique legacy account ID and its account code in CSV record $line.");
            }
            $debit = $this->minor($debit, $line);
            $credit = $this->minor($credit, $line);
            if (($debit > 0) === ($credit > 0)) {
                $this->invalid("CSV record $line must have exactly one nonzero side.");
            }
            $rows[(int) $id] = ['legacy_id' => (int) $id, 'code' => $code, 'signed_minor' => $debit - $credit];
        }

        if (count($rows) < 2 || array_sum(array_column($rows, 'signed_minor')) !== 0) {
            $this->invalid('The opening journal needs at least two accounts and equal debit and credit totals.');
        }

        return $rows;
    }

    public function plan(array $rows, string $date): array
    {
        if (DB::table('ledger_opening_journals')->exists()) {
            $this->invalid('An opening journal has already been posted.');
        }

        $sources = [
            'loan_disbursements' => 'transferred_on', 'loan_repayment_receipts' => 'received_on',
            'member_fund_transactions' => 'effective_on', 'loan_journal_entries' => 'transaction_date',
            'member_fund_journals' => 'transaction_date', 'ledger_journal_reversals' => 'transaction_date',
            'general_journals' => 'transaction_date', 'chart_account_transfers' => 'transaction_date',
        ];
        foreach ($sources as $table => $column) {
            if (DB::table($table)->where($column, '<', $date)->exists()) {
                $this->invalid('Earlier operational records already exist. Choose a cutover date on or before their earliest date to avoid overlapping balances.');
            }
        }
        if ($this->periods->closed(substr($date, 0, 7).'-01')) {
            $this->invalid('The cutover month is closed.');
        }

        $accounts = DB::table('chart_accounts')->whereIntegerInRaw('legacy_id', array_keys($rows))
            ->get(['id', 'legacy_id', 'code', 'name', 'is_ledger_account'])->keyBy('legacy_id');
        $plan = [];
        foreach ($rows as $id => $row) {
            $account = $accounts->get($id);
            $status = match (true) {
                $account === null => 'Legacy account ID not found',
                $account->code !== $row['code'] => 'Account code does not match',
                ! $account->is_ledger_account => 'Account is unavailable for posting',
                default => 'Ready',
            };
            $plan[] = [...$row, 'account_id' => $account?->id, 'name' => $account?->name, 'status' => $status];
        }

        return $plan;
    }

    public function post(array $preview, int $actor): void
    {
        DB::transaction(function () use ($preview, $actor): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['ledger-cutover']);
            $date = $preview['effective_on'];
            $this->periods->requireOpenDates([$date], 'effective_on');
            $plan = $this->plan($preview['rows'], $date);
            if ($plan !== $preview['plan'] || collect($plan)->contains(fn (array $row): bool => $row['status'] !== 'Ready')) {
                $this->invalid('Resolve the exceptions or rerun the preview before posting.');
            }

            $journal = DB::table('ledger_opening_journals')->insertGetId([
                'effective_on' => $date, 'review_reference' => $preview['review_reference'],
                'source_sha256' => $preview['sha256'], 'posted_by' => $actor, 'posted_at' => now(),
            ]);
            foreach (array_chunk($plan, 250) as $batch) {
                DB::table('ledger_opening_lines')->insert(array_map(static fn (array $row): array => [
                    'ledger_opening_journal_id' => $journal, 'chart_account_id' => $row['account_id'],
                    'signed_minor' => $row['signed_minor'],
                ], $batch));
            }
        }, 3);
    }

    private function minor(string $value, int $line): int
    {
        if ($value === '') {
            return 0;
        }
        if (! preg_match('/\A\d{1,12}(?:\.\d{1,2})?\z/', $value)) {
            $this->invalid("Use plain TZS amounts with up to two decimal places in CSV record $line.");
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['opening_journal' => $message]);
    }
}
