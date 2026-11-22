<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class MonthlyMemberStatementImport
{
    public const Headers = ['registration_no', 'email', 'loan_balance', 'monthly_contribution', 'savings_balance'];

    public function period(string $month): string
    {
        if (! preg_match('/\A[2-9]\d{3}-(?:0[1-9]|1[0-2])\z/', $month)
            || $month >= now('Africa/Dar_es_Salaam')->format('Y-m')) {
            throw new InvalidArgumentException('Supply a closed month in YYYY-MM format.');
        }

        return $month.'-01';
    }

    public function readCsv(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('Supply a readable CSV of up to 10 MB.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException('The CSV could not be read.');
        }

        $file = fopen('php://temp', 'w+b');
        try {
            fwrite($file, $contents);
            rewind($file);
            $header = fgetcsv($file, escape: '') ?: [];
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
            if ($header !== self::Headers) {
                throw new InvalidArgumentException('Expected CSV headers: '.implode(',', self::Headers));
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($file, escape: '')) !== false) {
                $line++;

                if ($values === [null]) {
                    continue;
                }

                if (count($values) !== count(self::Headers)) {
                    throw new InvalidArgumentException("Unexpected column count in CSV record $line.");
                }

                $rows[$line] = array_combine(self::Headers, array_map('trim', $values));
            }
        } finally {
            fclose($file);
        }

        if ($rows === []) {
            throw new InvalidArgumentException('The CSV has no statement records.');
        }

        return [hash('sha256', $contents), $rows];
    }

    public function plan(string $period, array $source, bool $lock = false): array
    {
        [$checksum, $rows] = $source;
        $members = DB::table('members')->whereIn('registration_no', array_column($rows, 'registration_no'))
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'registration_no', 'email', 'first_name', 'last_name'])->keyBy('registration_no');
        $existing = DB::table('monthly_member_statements')->where('period', $period)->get()->keyBy('member_id');
        $seen = [];
        $pending = [];
        $money = ['required', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/'];

        foreach ($rows as $line => $row) {
            $validator = Validator::make($row, [
                'registration_no' => ['required', 'max:30', 'regex:/\A[A-Z0-9-]+\z/'],
                'email' => ['required', 'email', 'max:255'],
                'loan_balance' => $money,
                'monthly_contribution' => $money,
                'savings_balance' => $money,
            ]);

            if ($validator->fails()) {
                throw new InvalidArgumentException("Invalid fields in CSV record $line: ".implode(', ', $validator->errors()->keys()).'.');
            }

            $member = $members->get($row['registration_no']);
            $recipient = strtolower($row['email']);
            if (! $member || strtolower($member->email) !== $recipient || isset($seen[$member->id])) {
                throw new InvalidArgumentException("CSV record $line has an unknown member, unmatched email, or repeated member.");
            }

            $seen[$member->id] = true;
            $record = [
                'recipient' => $recipient,
                'loan_balance_minor' => $this->toMinorUnits($row['loan_balance']),
                'contribution_minor' => $this->toMinorUnits($row['monthly_contribution']),
                'savings_balance_minor' => $this->toMinorUnits($row['savings_balance']),
            ];

            if ($previous = $existing->get($member->id)) {
                foreach ($record as $field => $value) {
                    if ($value !== $previous->$field) {
                        throw new InvalidArgumentException("CSV record $line conflicts with an existing statement.");
                    }
                }

                continue;
            }

            $pending[] = [
                ...$record,
                'member_id' => $member->id,
                'period' => $period,
                'source_sha256' => $checksum,
                'registration_no' => $member->registration_no,
                'name' => $member->first_name.' '.$member->last_name,
            ];
        }

        return $pending;
    }

    public function queue(array $plan, ?int $actor = null, ?string $reference = null): int
    {
        $now = now();
        foreach (array_chunk($plan, 500) as $batch) {
            DB::table('monthly_member_statements')->insert(array_map(static function (array $row) use ($now, $actor, $reference): array {
                unset($row['registration_no'], $row['name']);

                return [...$row, 'queued_by' => $actor, 'review_reference' => $reference, 'created_at' => $now, 'updated_at' => $now];
            }, $batch));
        }

        return count($plan);
    }

    private function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
