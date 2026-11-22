<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;

class StageLegacyOnboarding extends Command
{
    protected $signature = 'legacy:onboarding {dump : Path to the MySQL export}
        {--loans : Audit loans, installments, and repayments}
        {--savings : Audit savings, shares, and monthly contribution records}
        {--stage : Save the reviewed fields and issue flags to PostgreSQL staging}';

    protected $description = 'Audit legacy onboarding, loans, or savings and optionally stage the source rows';

    private const Fields = [
        'members' => 'member_id registration_no mem_no first_name middle_name last_name email phone joining_date status currency monthsaving monthshare autopay created_at updated_at',
        'categoryaccounts' => 'id name code created_at updated_at',
        'categoryaccountstypes' => 'id name code ref_name categoryaccount_id created_at updated_at',
        'mainaccounts' => 'id name name_sw name_eng account_no categoryaccountstype_id liableaccount_id is_ledger_account date created_at updated_at',
        'memberaccounts' => 'id member_id mainaccount_id account_no name categoryaccount_id date created_at updated_at',
        'regfees' => 'id member_id payment_id amount status date created_at updated_at',
    ];

    private const LoanFields = [
        'members' => 'member_id',
        'loancategories' => 'id category_name category_code default_duration interest_rate status',
        'loans' => 'id member_id loan_code loancategory_id loan_status loanInssue_date disbursement_date duration interestmethod_id interest principle repayment_date no_of_installments',
        'loanschedules' => 'id loan_id month monthprinciple monthinterest status r_status duedate scheduled_totalpayment totalpayment',
        'repayments' => 'id payment_id rploan_id monthly_id monthly_type loanschedule_id amountpayed paymentdate principlepayed interestpayed',
    ];

    private const SavingsFields = [
        'members' => 'member_id',
        'memberaccounts' => 'id member_id',
        'membersavingaccounts' => 'id member_id memberaccount_id payment_id monthly_id monthly_type dr cr description date',
        'membershareaccounts' => 'id member_id memberaccount_id payment_id monthly_id monthly_type dr cr description date no_shares',
        'monthpaymentlists' => 'id member_id payment_id date month year shares savings status',
    ];

    private const References = [
        'categoryaccountstypes' => ['categoryaccount_id' => 'categoryaccounts'],
        'mainaccounts' => ['categoryaccountstype_id' => 'categoryaccountstypes'],
        'memberaccounts' => ['member_id' => 'members', 'mainaccount_id' => 'mainaccounts', 'categoryaccount_id' => 'categoryaccounts'],
        'regfees' => ['member_id' => 'members'],
    ];

    public function handle(): int
    {
        $loans = $this->option('loans');
        $savings = $this->option('savings');
        if ($loans && $savings) {
            $this->error('Select one staging scope per run.');

            return self::FAILURE;
        }
        $fields = match (true) {
            $loans => self::LoanFields,
            $savings => self::SavingsFields,
            default => self::Fields,
        };

        try {
            [$rows, $sourceHash] = $this->readDump($this->argument('dump'), $fields);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($loans) {
            $issues = $this->auditLoans($rows);
            unset($rows['members']);
            $links = array_count_values(array_column($rows['repayments'], 'loan_link'));
            $this->table(['Repayment loan link', 'Rows'], array_map(null, array_keys($links), array_values($links)));
        } elseif ($savings) {
            $issues = $this->auditSavings($rows);
            unset($rows['members'], $rows['memberaccounts']);
        } else {
            $issues = $this->audit($rows);
        }
        $summary = [];
        $issueCounts = [];

        foreach ($rows as $table => $records) {
            $summary[] = [$table, count($records), count($issues[$table] ?? [])];

            foreach ($issues[$table] ?? [] as $flags) {
                foreach ($flags as $flag) {
                    $issueCounts[$table][$flag] = ($issueCounts[$table][$flag] ?? 0) + 1;
                }
            }
        }

        $this->line("Source SHA-256 $sourceHash");
        $this->table(['Source table', 'Rows', 'Rows with issues'], $summary);
        $details = [];
        foreach ($issueCounts as $table => $counts) {
            foreach ($counts as $flag => $count) {
                $details[] = [$table, $flag, $count];
            }
        }
        $this->table(['Source table', 'Issue', 'Rows'], $details);

        if ($this->option('stage')) {
            $this->stage($rows, $issues, $sourceHash);
            $this->info('Snapshot saved to legacy_onboarding_rows.');
        } else {
            $this->info('Dry run complete. No rows written.');
        }

        return self::SUCCESS;
    }

    private function readDump(string $path, array $fields): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The dump must be a readable file.');
        }

        $rows = array_fill_keys(array_keys($fields), []);
        $hash = hash_init('sha256');
        $tables = [];
        $table = null;
        $file = new SplFileObject($path);

        foreach ($file as $offset => $line) {
            hash_update($hash, $line);

            if ($table === null) {
                if (preg_match('/^CREATE TABLE \x60([^\x60]+)\x60 \(/', $line, $definition)) {
                    $tables[$definition[1]] = true;
                }

                if (preg_match('/^INSERT INTO \x60([^\x60]+)\x60 \((.+)\) VALUES\s*$/', $line, $insert)
                    && isset($fields[$insert[1]])) {
                    $table = $insert[1];
                    $wanted = array_flip(explode(' ', $fields[$table]));
                    preg_match_all('/\x60([^\x60]+)\x60/', $insert[2], $matches);
                    $columns = $matches[1];
                    $idColumn = $table === 'members' ? 'member_id' : 'id';

                    if (array_diff_key($wanted, array_flip($columns))) {
                        throw new RuntimeException("Missing expected columns in $table at line ".($offset + 1).'.');
                    }
                }

                continue;
            }

            $lineNumber = $offset + 1;
            $values = $this->readTuple($line, count($columns), $table, $lineNumber);
            $record = array_intersect_key(array_combine($columns, $values), $wanted);
            $id = $record[$idColumn];

            if (! ctype_digit($id ?? '') || (int) $id < 1 || isset($rows[$table][(int) $id])) {
                throw new RuntimeException("Invalid or duplicate source ID in $table at line $lineNumber.");
            }

            $rows[$table][(int) $id] = $record;

            if (str_ends_with(rtrim($line), ';')) {
                $table = null;
            }
        }

        if ($table !== null || array_diff_key($fields, $tables)) {
            throw new RuntimeException('The dump is incomplete or does not contain all required tables.');
        }

        return [$rows, hash_final($hash)];
    }

    private function readTuple(string $line, int $columns, string $table, int $lineNumber): array
    {
        $valuePattern = <<<'REGEX'
~\G\s*('(?:[^'\\]|\\.)*'|NULL|[+-]?\d+(?:\.\d*)?(?:[eE][+-]?\d+)?)\s*([,)])~s
REGEX;

        preg_match_all($valuePattern, $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, 1);
        $last = end($matches);
        if (! str_starts_with($line, '(') || count($matches) !== $columns || $last[2][0] !== ')'
            || ! in_array(trim(substr($line, $last[2][1] + 1)), [',', ';'], true)) {
            throw new RuntimeException("Unsupported row format in $table at line $lineNumber.");
        }

        return array_map(static function (array $match): ?string {
            $value = $match[1][0];

            if ($value === 'NULL') {
                return null;
            }

            if (! str_starts_with($value, "'")) {
                return $value;
            }

            return strtr(substr($value, 1, -1), [
                '\\0' => "\0",
                '\\n' => "\n",
                '\\r' => "\r",
                '\\t' => "\t",
                '\\b' => "\x08",
                '\\Z' => "\x1a",
                "\\'" => "'",
                '\\"' => '"',
                '\\\\' => '\\',
            ]);
        }, $matches);
    }

    private function audit(array $rows): array
    {
        $issues = [];

        foreach (['registration_no', 'email', 'phone'] as $field) {
            $groups = [];
            foreach ($rows['members'] as $id => $member) {
                $value = Str::lower(trim($member[$field] ?? ''));
                if ($value === '') {
                    $issues['members'][$id][] = "missing_$field";
                } else {
                    $groups[$value][] = $id;
                }
            }

            foreach ($groups as $ids) {
                if (count($ids) > 1) {
                    foreach ($ids as $id) {
                        $issues['members'][$id][] = "duplicate_$field";
                    }
                }
            }
        }

        foreach ($rows['members'] as $id => $member) {
            $date = $member['joining_date'] ?? '';
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (! $parsed || $parsed->format('Y-m-d') !== $date || str_starts_with($date, '0000')) {
                $issues['members'][$id][] = 'invalid_joining_date';
            }
        }

        foreach (self::References as $table => $references) {
            foreach ($rows[$table] as $id => $record) {
                foreach ($references as $field => $parent) {
                    if (! isset($rows[$parent][$record[$field] ?? ''])) {
                        $issues[$table][$id][] = "unresolved_$field";
                    }
                }
            }
        }

        return $issues;
    }

    private function auditLoans(array &$rows): array
    {
        $issues = [];
        foreach ($rows['loans'] as $id => $loan) {
            if (! isset($rows['members'][$loan['member_id']])) {
                $issues['loans'][$id][] = 'unresolved_member_id';
            }
            if (! isset($rows['loancategories'][$loan['loancategory_id']])) {
                $issues['loans'][$id][] = 'unresolved_loancategory_id';
            }
        }

        foreach ($rows['loanschedules'] as $id => $schedule) {
            if (! isset($rows['loans'][$schedule['loan_id']])) {
                $issues['loanschedules'][$id][] = 'unresolved_loan_id';
            }
        }

        foreach ($rows['repayments'] as $id => &$repayment) {
            $schedule = $rows['loanschedules'][$repayment['loanschedule_id']] ?? null;
            $scheduledLoan = $schedule['loan_id'] ?? null;
            $directLoan = $repayment['rploan_id'];
            $viaSchedule = isset($rows['loans'][$scheduledLoan]) ? $scheduledLoan : null;
            $direct = isset($rows['loans'][$directLoan]) ? $directLoan : null;

            $link = match (true) {
                $direct !== null && $viaSchedule !== null && $direct !== $viaSchedule => 'conflict',
                $direct !== null && $viaSchedule !== null => 'both_agree',
                $viaSchedule !== null => 'schedule_only',
                $direct !== null => 'direct_only',
                default => 'unresolved',
            };
            $repayment['loan_link'] = $link;
            $repayment['resolved_loan_id'] = in_array($link, ['conflict', 'unresolved'], true)
                ? null : ($viaSchedule ?? $direct);

            if ($schedule === null) {
                $issues['repayments'][$id][] = 'unresolved_loanschedule_id';
            }
            if (in_array($link, ['conflict', 'unresolved'], true)) {
                $issues['repayments'][$id][] = $link.'_loan_link';
            }
        }
        unset($repayment);

        return $issues;
    }

    private function auditSavings(array $rows): array
    {
        $issues = [];
        foreach (['membersavingaccounts', 'membershareaccounts', 'monthpaymentlists'] as $table) {
            foreach ($rows[$table] as $id => $data) {
                if (! isset($rows['members'][$data['member_id']])) {
                    $issues[$table][$id][] = 'unresolved_member_id';
                }
                if ($table === 'monthpaymentlists') {
                    if (! preg_match('/\\A(?:19|20)[0-9]{2}\\z/', $data['year'] ?? '')
                        || ! preg_match('/\\A(?:0?[1-9]|1[0-2])\\z/', $data['month'] ?? '')) {
                        $issues[$table][$id][] = 'invalid_contribution_period';
                    }

                    continue;
                }

                $memberAccount = $rows['memberaccounts'][$data['memberaccount_id']] ?? null;
                if ($memberAccount === null) {
                    $issues[$table][$id][] = 'unresolved_memberaccount_id';
                } elseif ($memberAccount['member_id'] !== $data['member_id']) {
                    $issues[$table][$id][] = 'member_account_mismatch';
                }

                $date = $data['date'] ?? '';
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (! $parsed || $parsed->format('Y-m-d') !== $date || str_starts_with($date, '0000')) {
                    $issues[$table][$id][] = 'invalid_transaction_date';
                }
            }
        }

        return $issues;
    }

    private function stage(array $rows, array $issues, string $sourceHash): void
    {
        DB::transaction(function () use ($rows, $issues, $sourceHash): void {
            foreach ($rows as $table => $records) {
                foreach (array_chunk($records, 500, true) as $chunk) {
                    $batch = [];

                    foreach ($chunk as $id => $record) {
                        $batch[] = [
                            'source_sha256' => $sourceHash,
                            'source_table' => $table,
                            'source_id' => $id,
                            'data' => json_encode($record, JSON_THROW_ON_ERROR),
                            'issues' => json_encode($issues[$table][$id] ?? [], JSON_THROW_ON_ERROR),
                        ];
                    }

                    DB::table('legacy_onboarding_rows')->upsert(
                        $batch,
                        ['source_sha256', 'source_table', 'source_id'],
                        ['data', 'issues'],
                    );
                }
            }
        }, 3);
    }
}
