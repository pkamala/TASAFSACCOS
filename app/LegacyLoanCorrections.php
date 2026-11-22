<?php

namespace App;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LegacyLoanCorrections
{
    public const Flags = [
        'loanschedules' => ['unresolved_loan_id'],
        'repayments' => ['conflict_loan_link', 'unresolved_loan_link', 'unresolved_loanschedule_id'],
    ];

    private const Headers = [
        'snapshot_sha256', 'source_table', 'source_id', 'confirmed_legacy_loan_id', 'confirmed_legacy_schedule_id',
        'evidence_reference', 'reviewed_by', 'reviewed_on',
    ];

    public function read(UploadedFile $upload, string $snapshot): array
    {
        $file = $upload->openFile();
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv() ?: [];
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
        if ($header !== self::Headers) {
            $this->fail('Use the column headers from the legacy link corrections template.');
        }

        $rows = [];
        $recordNumber = 1;
        $today = now('Africa/Dar_es_Salaam')->toDateString();
        while (! $file->eof()) {
            $values = $file->fgetcsv();
            $recordNumber++;
            if ($values === false || $values === [null]) {
                continue;
            }
            if (count($values) !== count(self::Headers) || count($rows) >= 1000) {
                $this->fail("Invalid column count or 1,000-row limit exceeded at CSV record $recordNumber.");
            }

            $row = array_combine(self::Headers, array_map('trim', $values));
            if (preg_match('//u', implode('', $row)) !== 1 || str_contains(implode('', $row), "\0")) {
                $this->fail("CSV record $recordNumber must contain valid UTF-8 text.");
            }

            $id = ['integer', 'min:1', 'max:2147483647'];
            $validator = Validator::make($row, [
                'snapshot_sha256' => ['required', 'in:'.$snapshot],
                'source_table' => ['required', 'in:loanschedules,repayments'],
                'source_id' => ['required', ...$id],
                'confirmed_legacy_loan_id' => ['required', ...$id],
                'confirmed_legacy_schedule_id' => ['required_if:source_table,repayments', 'prohibited_if:source_table,loanschedules', ...$id],
                'evidence_reference' => ['required', 'max:500'],
                'reviewed_by' => ['required', 'max:255'],
                'reviewed_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$today],
            ]);
            if ($validator->fails()) {
                $this->fail("CSV record $recordNumber has invalid fields: ".implode(', ', $validator->errors()->keys()).'.');
            }

            $rows[$recordNumber] = [
                'source_table' => $row['source_table'],
                'source_id' => (int) $row['source_id'],
                'loan_id' => (int) $row['confirmed_legacy_loan_id'],
                'schedule_id' => $row['confirmed_legacy_schedule_id'] === '' ? null : (int) $row['confirmed_legacy_schedule_id'],
                'evidence_reference' => $row['evidence_reference'],
                'reviewed_by' => $row['reviewed_by'],
                'reviewed_on' => $row['reviewed_on'],
            ];
        }

        if ($rows === []) {
            $this->fail('The CSV has no correction records.');
        }

        return $rows;
    }

    public function validateLinks(string $snapshot, array $rows): void
    {
        $sources = DB::table('legacy_onboarding_rows')->where('source_sha256', $snapshot);
        $existing = DB::table('legacy_loan_corrections')->where('source_sha256', $snapshot)
            ->get()->groupBy('source_table')->map->keyBy('source_id');
        $loanIds = array_column($rows, 'loan_id');
        $scheduleIds = array_filter(array_column($rows, 'schedule_id'));
        $repaymentIds = [];
        $changedSchedules = [];
        $seen = [];

        foreach ($rows as $line => $row) {
            $table = $row['source_table'];
            $id = $row['source_id'];
            if (isset($seen[$table][$id]) || $existing->get($table)?->has($id)) {
                $this->fail("CSV record $line repeats a correction or refers to a row already corrected.");
            }
            $seen[$table][$id] = true;

            if ($table === 'loanschedules') {
                $changedSchedules[$id] = $row['loan_id'];
                $scheduleIds[] = $id;
            } else {
                $repaymentIds[] = $id;
            }
        }

        $loans = (clone $sources)->where('source_table', 'loans')->whereIntegerInRaw('source_id', $loanIds)->pluck('issues', 'source_id');
        $schedules = (clone $sources)->where('source_table', 'loanschedules')->whereIntegerInRaw('source_id', $scheduleIds)
            ->selectRaw("source_id, issues, data->>'loan_id' AS loan_id")->get()->keyBy('source_id');
        $repayments = (clone $sources)->where('source_table', 'repayments')->whereIntegerInRaw('source_id', $repaymentIds)->pluck('issues', 'source_id');

        foreach ($rows as $line => $row) {
            $table = $row['source_table'];
            $id = $row['source_id'];
            $loan = $row['loan_id'];
            $flags = $table === 'loanschedules' ? $schedules->get($id)?->issues : $repayments->get($id);

            if ($flags === null || ! array_intersect(self::Flags[$table], json_decode($flags, true, flags: JSON_THROW_ON_ERROR))) {
                $this->fail("CSV record $line must refer to a staged row flagged for this type of correction.");
            }

            if ($loans->get($loan) !== '[]') {
                $this->fail("CSV record $line must identify a staged loan with a verified member and loan product.");
            }

            if ($table === 'repayments') {
                $scheduleId = $row['schedule_id'];
                $schedule = $schedules->get($scheduleId);
                $scheduleLoan = $changedSchedules[$scheduleId]
                    ?? $existing->get('loanschedules')?->get($scheduleId)?->loan_id
                    ?? $schedule?->loan_id;

                if ($schedule === null || (string) $scheduleLoan !== (string) $loan) {
                    $this->fail("CSV record $line points to an installment that does not belong to the confirmed loan.");
                }
            }
        }
    }

    public function apply(string $snapshot, array $rows, string $checksum, int $actor): void
    {
        DB::transaction(function () use ($snapshot, $rows, $checksum, $actor): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['legacy-loan-corrections:'.$snapshot]);
            $this->validateLinks($snapshot, $rows);
            $now = now();

            foreach (array_chunk($rows, 250) as $chunk) {
                DB::table('legacy_loan_corrections')->insert(array_map(static fn (array $row): array => [
                    ...$row,
                    'source_sha256' => $snapshot,
                    'csv_sha256' => $checksum,
                    'applied_by' => $actor,
                    'applied_at' => $now,
                ], $chunk));
            }
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['corrections' => $message]);
    }
}
