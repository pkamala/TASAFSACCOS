<?php

namespace App;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VerifiedBalanceImports
{
    private const Fields = ['registration_no', 'legacy_member_id', 'as_of', 'savings_tzs', 'shares_tzs', 'evidence_reference'];

    public function read(UploadedFile $upload): array
    {
        $file = $upload->openFile();
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv() ?: [];
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
        if ($header !== self::Fields) {
            $this->invalid('Use the headers from the verified opening balances template.');
        }

        $rows = [];
        $line = 1;
        $today = now('Africa/Dar_es_Salaam')->toDateString();
        while (! $file->eof()) {
            $values = $file->fgetcsv();
            $line++;
            if ($values === false || $values === [null]) {
                continue;
            }
            if (count($values) !== count(self::Fields) || count($rows) >= 1000) {
                $this->invalid("Invalid column count or 1,000-row limit exceeded at CSV record $line.");
            }

            $data = array_combine(self::Fields, array_map('trim', $values));
            $text = implode('', $data);
            if (preg_match('//u', $text) !== 1 || str_contains($text, "\0")) {
                $this->invalid("Use valid UTF-8 text in CSV record $line.");
            }
            $data['registration_no'] = strtoupper($data['registration_no']);
            $money = ['required', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/'];
            $validator = Validator::make($data, [
                'registration_no' => ['required', 'regex:/\A[A-Z0-9-]{1,30}\z/'],
                'legacy_member_id' => ['integer', 'min:1', 'max:2147483647'],
                'as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$today],
                'savings_tzs' => $money,
                'shares_tzs' => $money,
                'evidence_reference' => ['required', 'max:500'],
            ]);
            if ($validator->fails()) {
                $this->invalid("Invalid fields in CSV record $line: ".implode(', ', $validator->errors()->keys()).'.');
            }

            if (isset($rows[$data['registration_no']])) {
                $this->invalid("Membership number repeated in CSV record $line.");
            }
            $rows[$data['registration_no']] = [
                'line' => $line,
                'registration_no' => $data['registration_no'],
                'legacy_member_id' => $data['legacy_member_id'] === '' ? null : (int) $data['legacy_member_id'],
                'as_of' => $data['as_of'],
                'savings_minor' => $this->minor($data['savings_tzs']),
                'shares_minor' => $this->minor($data['shares_tzs']),
                'evidence_reference' => $data['evidence_reference'],
            ];
        }

        if ($rows === []) {
            $this->invalid('The CSV has no opening balances.');
        }

        return $rows;
    }

    public function plan(array $rows, bool $lock = false): array
    {
        $members = DB::table('members')->whereIn('registration_no', array_column($rows, 'registration_no'))->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'registration_no', 'legacy_member_id', 'first_name', 'last_name'])->keyBy('registration_no');
        $ids = $members->pluck('id');
        $current = DB::table('member_verified_balances')->whereIn('member_id', $ids)
            ->selectRaw('DISTINCT ON (member_id) member_id, id, as_of, savings_minor, shares_minor, evidence_reference')
            ->orderBy('member_id')->orderByDesc('as_of')->orderByDesc('id')->get()->keyBy('member_id');
        $locked = DB::table('member_fund_transactions')->whereIn('member_id', $ids)->select('member_id')
            ->union(DB::table('member_savings_withdrawals')->whereIn('member_id', $ids)
                ->where('status', 'approved')->select('member_id'))->pluck('member_id')->flip();

        $plan = [];
        foreach ($rows as $row) {
            $member = $members->get($row['registration_no']);
            $existing = $member === null ? null : $current->get($member->id);
            $identical = $existing !== null
                && $existing->as_of === $row['as_of']
                && $existing->savings_minor === $row['savings_minor']
                && $existing->shares_minor === $row['shares_minor']
                && $existing->evidence_reference === $row['evidence_reference'];

            $status = match (true) {
                $member === null => 'Membership number not found',
                $member->legacy_member_id !== $row['legacy_member_id'] => 'Legacy member ID does not match',
                $identical => 'Already recorded',
                $locked->has($member->id) => 'Opening balance is locked by member activity',
                $existing !== null && $existing->as_of > $row['as_of'] => 'A newer balance is already verified',
                default => 'Ready',
            };
            $plan[] = [
                ...$row, 'member_id' => $member?->id, 'name' => $member ? $member->first_name.' '.$member->last_name : null,
                'current_id' => $existing?->id, 'status' => $status,
            ];
        }

        return $plan;
    }

    public function apply(array $source, array $reviewed, string $checksum, int $actor): int
    {
        return DB::transaction(function () use ($source, $reviewed, $checksum, $actor): int {
            $plan = $this->plan($source, lock: true);
            if ($plan !== $reviewed || collect($plan)->contains(fn (array $row): bool => ! in_array($row['status'], ['Ready', 'Already recorded'], true))) {
                $this->invalid('The preview has exceptions or the balances changed. Run a fresh dry run.');
            }

            $records = [];
            $now = now();
            foreach (collect($plan)->where('status', 'Ready') as $row) {
                $records[] = [
                    ...array_intersect_key($row, array_flip(['member_id', 'as_of', 'savings_minor', 'shares_minor', 'evidence_reference'])),
                    'recorded_by' => $actor, 'recorded_at' => $now, 'import_sha256' => $checksum,
                ];
            }
            foreach (array_chunk($records, 200) as $batch) {
                DB::table('member_verified_balances')->insert($batch);
            }

            return count($records);
        }, 3);
    }

    private function minor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['opening_balances' => $message]);
    }
}
