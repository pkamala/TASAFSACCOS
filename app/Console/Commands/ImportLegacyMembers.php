<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ImportLegacyMembers extends Command
{
    protected $signature = 'legacy:import-members
        {snapshot : SHA-256 of the staged export}
        {--renumber=* : Approved legacy ID and replacement number, e.g. 53=10415}
        {--apply : Write the validated members to the operational database}';

    protected $description = 'Validate or apply the legacy member identity mapping';

    public function handle(): int
    {
        $snapshot = $this->argument('snapshot');
        if (! preg_match('/\A[a-f0-9]{64}\z/', $snapshot)) {
            $this->error('Supply the staged snapshot SHA-256.');

            return self::FAILURE;
        }

        try {
            $replacements = $this->replacements();
            $count = DB::transaction(fn (): int => $this->import($snapshot, $replacements), 3);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (UniqueConstraintViolationException) {
            $this->error('The member identities changed during the import. Rerun the dry run.');

            return self::FAILURE;
        }

        $this->info($this->option('apply') ? "$count legacy members imported." : "$count legacy members ready to import.");

        return self::SUCCESS;
    }

    private function replacements(): array
    {
        $replacements = [];
        foreach ($this->option('renumber') as $pair) {
            if (! preg_match('/\A([1-9]\d*)=([A-Za-z0-9-]{1,30})\z/', $pair, $match)
                || isset($replacements[$match[1]])) {
                throw new InvalidArgumentException('Use one --renumber=LEGACY_ID=NEW_NUMBER per member.');
            }

            $replacements[$match[1]] = Str::upper($match[2]);
        }

        return $replacements;
    }

    private function import(string $snapshot, array $replacements): int
    {
        $source = DB::table('legacy_onboarding_rows')
            ->where('source_sha256', $snapshot)
            ->where('source_table', 'members')
            ->orderBy('source_id')
            ->get()
            ->keyBy('source_id');

        if ($source->isEmpty() || array_diff_key($replacements, $source->all())) {
            throw new InvalidArgumentException('The snapshot or one of the selected legacy member IDs was not found.');
        }

        $existing = DB::table('members')->get(['legacy_member_id', 'legacy_source_sha256', 'registration_no', 'email']);
        $byLegacyId = $existing->whereNotNull('legacy_member_id')->keyBy('legacy_member_id');
        $numbers = $existing->keyBy(fn ($member): string => Str::upper(trim($member->registration_no)))->all();
        $emails = $existing->keyBy(fn ($member): string => Str::lower(trim($member->email)))->all();
        $pending = [];
        $now = now();

        foreach ($source as $id => $entry) {
            $raw = json_decode($entry->data, true, flags: JSON_THROW_ON_ERROR);
            $issues = json_decode($entry->issues, true, flags: JSON_THROW_ON_ERROR);
            $number = $replacements[$id] ?? Str::upper(trim($raw['registration_no'] ?? ''));

            if ($previous = $byLegacyId->get($id)) {
                if ($previous->legacy_source_sha256 !== $snapshot || $previous->registration_no !== $number) {
                    throw new InvalidArgumentException("Legacy member $id already has a different operational mapping.");
                }

                continue;
            }

            $record = [
                'legacy_member_id' => $id,
                'legacy_registration_no' => $raw['registration_no'],
                'legacy_source_sha256' => $snapshot,
                'registration_no' => $number,
                'first_name' => trim($raw['first_name'] ?? ''),
                'middle_name' => trim($raw['middle_name'] ?? '') ?: null,
                'last_name' => trim($raw['last_name'] ?? ''),
                'email' => Str::lower(trim($raw['email'] ?? '')),
                'phone' => trim($raw['phone'] ?? '') ?: null,
                'joining_date' => in_array('invalid_joining_date', $issues, true) ? null : $raw['joining_date'],
                'status' => Str::lower(trim($raw['status'] ?? '')),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $validator = Validator::make($record, [
                'legacy_registration_no' => ['nullable', 'max:30'],
                'registration_no' => ['required', 'max:30', 'regex:/\A[A-Z0-9-]+\z/'],
                'first_name' => ['required', 'max:100'],
                'middle_name' => ['nullable', 'max:100'],
                'last_name' => ['required', 'max:100'],
                'email' => ['required', 'email', 'max:255'],
                'phone' => ['nullable', 'max:16'],
                'joining_date' => ['nullable', 'date_format:Y-m-d'],
                'status' => ['required', 'in:active,inactive'],
            ]);

            if ($validator->fails()) {
                throw new InvalidArgumentException("Legacy member $id has invalid fields: ".implode(', ', $validator->errors()->keys()).'.');
            }

            if (isset($numbers[$number]) || isset($emails[$record['email']])) {
                throw new InvalidArgumentException("Legacy member $id conflicts with another registration number or email.");
            }

            $numbers[$number] = true;
            $emails[$record['email']] = true;
            $pending[] = $record;
        }

        if ($this->option('apply') && $pending !== []) {
            DB::table('members')->insert($pending);
        }

        return count($pending);
    }
}
