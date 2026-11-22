<?php

namespace App\Console\Commands;

use App\Mail\MonthlyMemberStatementMail;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendMonthlyMemberStatements extends Command
{
    protected $signature = 'statements:send {period? : Closed month in YYYY-MM format, defaults to last month}
        {--send : Deliver the pending statements by email}';

    protected $description = 'Preview or send reviewed monthly member statements';

    public function handle(): int
    {
        $now = CarbonImmutable::now('Africa/Dar_es_Salaam');
        $period = $this->argument('period') ?? $now->subMonthNoOverflow()->format('Y-m');
        if (! preg_match('/\A[2-9]\d{3}-(?:0[1-9]|1[0-2])\z/', $period) || $period >= $now->format('Y-m')) {
            $this->error('Supply a closed month in YYYY-MM format.');

            return self::FAILURE;
        }

        $date = CarbonImmutable::parse($period.'-01', 'Africa/Dar_es_Salaam');
        $pending = DB::table('monthly_member_statements')->where('period', $date->toDateString())->where('delivery_status', 'pending');
        $this->info($pending->count()." pending statements for $period.");
        if (! $this->option('send')) {
            return self::SUCCESS;
        }

        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if (! in_array($transport, config('statements.delivery_transports'), true)) {
            $this->error('Configure a real mail transport before sending statements.');

            return self::FAILURE;
        }

        $sent = 0;
        $held = 0;
        $failed = 0;

        foreach ($pending->lazyById(100) as $statement) {
            $member = DB::table('members')->find($statement->member_id, ['email']);
            if (! $member || strtolower($member->email) !== $statement->recipient) {
                $held++;

                continue;
            }

            $claimed = DB::table('monthly_member_statements')->where('id', $statement->id)->where('delivery_status', 'pending')
                ->update(['delivery_status' => 'sending', 'attempted_at' => now(), 'updated_at' => now()]);
            if ($claimed !== 1) {
                continue;
            }

            try {
                Mail::to($statement->recipient)->send(new MonthlyMemberStatementMail(
                    $date->format('F Y'),
                    $date->endOfMonth()->format('d F Y'),
                    $statement->loan_balance_minor,
                    $statement->contribution_minor,
                    $statement->savings_balance_minor,
                ));
            } catch (Throwable) {
                DB::table('monthly_member_statements')->where('id', $statement->id)
                    ->update(['delivery_status' => 'needs_review', 'updated_at' => now()]);
                $this->warn("Check the provider before retrying statement ID {$statement->id}.");
                $failed++;

                continue;
            }

            DB::table('monthly_member_statements')->where('id', $statement->id)
                ->update(['delivery_status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
            $sent++;
        }

        $this->info("Sent: $sent. Held for email mismatch: $held. Delivery needs review: $failed.");

        return $held + $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
