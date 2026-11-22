<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ImportLegacyChart extends Command
{
    protected $signature = 'legacy:import-chart {snapshot : SHA-256 of the staged export} {--apply : Write the validated chart to PostgreSQL}';

    protected $description = 'Validate or import the staged chart of accounts';

    public function handle(): int
    {
        $snapshot = $this->argument('snapshot');
        if (! preg_match('/\A[a-f0-9]{64}\z/', $snapshot)) {
            $this->error('Supply the staged snapshot SHA-256.');

            return self::FAILURE;
        }

        try {
            $counts = DB::transaction(fn (): array => $this->import($snapshot), 3);
        } catch (UniqueConstraintViolationException) {
            $this->error('A concurrent import changed the chart. Rerun the command.');

            return self::FAILURE;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Target table', 'Source records', $this->option('apply') ? 'Imported' : 'Ready'], $counts);

        return self::SUCCESS;
    }

    private function import(string $snapshot): array
    {
        $levels = [
            'categoryaccounts' => 'account_categories',
            'categoryaccountstypes' => 'account_types',
            'mainaccounts' => 'chart_accounts',
        ];
        $sources = DB::table('legacy_onboarding_rows')
            ->where('source_sha256', $snapshot)
            ->whereIn('source_table', array_keys($levels))
            ->orderBy('source_id')
            ->get()
            ->groupBy('source_table');

        $ids = [];
        $counts = [];

        foreach ($levels as $sourceTable => $targetTable) {
            $records = $sources->get($sourceTable);
            if (! $records || $records->isEmpty()) {
                throw new InvalidArgumentException("Snapshot has no $sourceTable rows.");
            }
            $existing = DB::table($targetTable)->whereNotNull('legacy_id')->get(['id', 'legacy_id', 'legacy_source_sha256'])->keyBy('legacy_id');
            if ($existing->contains(fn ($row): bool => $row->legacy_source_sha256 !== $snapshot)) {
                throw new InvalidArgumentException("The $targetTable table belongs to a different snapshot.");
            }

            $imported = 0;
            foreach ($records as $entry) {
                $data = json_decode($entry->data, true, flags: JSON_THROW_ON_ERROR);
                $row = $this->map($sourceTable, $entry->source_id, $data, $ids);
                if ($previous = $existing->get($entry->source_id)) {
                    $ids[$sourceTable][$entry->source_id] = $previous->id;

                    continue;
                }

                $ids[$sourceTable][$entry->source_id] = $this->option('apply')
                    ? DB::table($targetTable)->insertGetId([
                        ...$row,
                        'legacy_id' => $entry->source_id,
                        'legacy_source_sha256' => $snapshot,
                    ])
                    : $entry->source_id;
                $imported++;
            }

            $counts[] = [$targetTable, $records->count(), $imported];
        }

        return $counts;
    }

    private function map(string $table, int $id, array $data, array $ids): array
    {
        $name = trim($data['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException("Invalid name for $table ID $id.");
        }

        return match ($table) {
            'categoryaccounts' => ['name' => $name, 'code' => $data['code']],
            'categoryaccountstypes' => [
                'name' => $name,
                'code' => $data['code'],
                'reference_name' => $data['ref_name'],
                'account_category_id' => $ids['categoryaccounts'][$data['categoryaccount_id']]
                    ?? throw new InvalidArgumentException("Missing parent category for account type $id."),
            ],
            'mainaccounts' => [
                'name' => $name,
                'code' => trim($data['account_no'] ?? '') ?: throw new InvalidArgumentException("Missing code for main account $id."),
                'name_sw' => $data['name_sw'],
                'name_en' => $data['name_eng'],
                'is_ledger_account' => $data['is_ledger_account'] === '1',
                'account_type_id' => $ids['categoryaccountstypes'][$data['categoryaccountstype_id']]
                    ?? throw new InvalidArgumentException("Missing parent type for main account $id."),
            ],
        };
    }
}
