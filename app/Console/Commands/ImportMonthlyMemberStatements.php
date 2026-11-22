<?php

namespace App\Console\Commands;

use App\MonthlyMemberStatementImport;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ImportMonthlyMemberStatements extends Command
{
    protected $signature = 'statements:import {period : Closed month in YYYY-MM format}
        {csv : CSV of reviewed balances and verified email addresses}
        {--apply : Save the statements for delivery}';

    protected $description = 'Validate or import reviewed monthly member statements';

    public function handle(MonthlyMemberStatementImport $import): int
    {
        try {
            $period = $import->period($this->argument('period'));
            $source = $import->readCsv($this->argument('csv'));
            $apply = $this->option('apply');
            $count = DB::transaction(function () use ($import, $period, $source, $apply): int {
                $plan = $import->plan($period, $source, lock: $apply);

                return $apply ? $import->queue($plan) : count($plan);
            }, 3);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (UniqueConstraintViolationException) {
            $this->error('Statements changed during import. Rerun the dry run.');

            return self::FAILURE;
        }

        $this->info($this->option('apply') ? "$count statements imported." : "$count statements ready to import.");

        return self::SUCCESS;
    }
}
