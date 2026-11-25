<?php

namespace App;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

class WorkflowTasks
{
    public function __construct(private LoanApproval $approval, private LoanSponsorship $sponsorship) {}

    public function forUser(User $user): Builder
    {
        abort_unless($user->can('access-task-inbox'), 403);
        $queries = match (true) {
            $user->isBoardOfficer() => $this->boardTasks($user),
            $user->is_staff_admin => $this->adminTasks(),
            default => [$this->loanReviews($user)],
        };
        $tasks = array_shift($queries);
        foreach ($queries as $query) {
            $tasks->unionAll($query);
        }

        return DB::query()->fromSub($tasks, 'task');
    }

    public function action(stdClass $task): array
    {
        [$title, $route, $parameters] = match ($task->type) {
            'loan-recommendation' => ['Recommend and forward loan application', 'staff.loan-applications.show', [$task->member_id, $task->reference]],
            'loan-review' => ['Review loan application', 'staff.loan-applications.show', [$task->member_id, $task->reference]],
            'loan-exit' => ['Review loan cancellation for member exit', 'staff.loan-applications.show', [$task->member_id, $task->reference]],
            'loan-disbursement' => ['Prepare approved loan for disbursement', 'staff.loan-applications.show', [$task->member_id, $task->reference]],
            'batch-review' => ['Review payment list', 'staff.loan-batches.show', [$task->reference]],
            'payroll-confirmation' => ['Review actual payroll deductions', 'staff.payroll-deductions.show', [$task->reference]],
            'disbursement-confirmation' => ['Confirm disbursement list', 'staff.loan-batches.show', [$task->reference]],
            'payroll-journal' => ['Post payroll journals', 'staff.payroll-journals.show', [$task->reference]],
            'savings-payment-journal' => ['Post savings repayment journal', 'staff.loan-journal.show', [$task->reference]],
            'disbursement-journal' => ['Post disbursement journal', 'staff.loan-journal.show', [$task->reference]],
            'withdrawal-review' => ['Review savings withdrawal', 'staff.savings-withdrawals.show', [$task->reference]],
            'withdrawal-pay' => ['Record approved withdrawal payment', 'staff.savings-withdrawals.show', [$task->reference]],
            'exit-review' => ['Review membership exit', 'staff.member-exits.show', [$task->reference]],
            'exit-finalize' => ['Finalize approved membership exit', 'staff.member-exits.show', [$task->reference]],
            'fund-journal' => ['Post member fund journal', 'staff.member-fund-journals.show', [$task->reference]],
            'statement-delivery' => ['Review statement delivery exceptions', 'staff.statement-review.index', ['period' => $task->reference]],
            'period-close' => ['Review month-end close', 'staff.ledger-periods.index', ['month' => $task->reference]],
        };

        return ['title' => $title, 'url' => route($route, $parameters)];
    }

    private function boardTasks(User $user): array
    {
        $loans = DB::table('loan_applications as loan')
            ->when($user->member_id !== null, fn (Builder $query) => $query->where('loan.member_id', '!=', $user->member_id))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('users as borrower')
                ->whereColumn('borrower.member_id', 'loan.member_id')->where('borrower.is_staff_admin', false)
                ->where('borrower.board_role', $user->board_role));
        $exits = DB::table('member_exits as request')->where('request.status', 'requested')->where('request.requested_by', '!=', $user->id)
            ->when($user->member_id !== null, fn (Builder $query) => $query->where('request.member_id', '!=', $user->member_id))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('users as borrower')
                ->whereColumn('borrower.member_id', 'request.member_id')->where('borrower.board_role', $user->board_role));
        $withdrawals = DB::table('member_savings_withdrawals as request')->where('request.status', 'requested')->where('request.requested_by', '!=', $user->id)
            ->when($user->member_id !== null, fn (Builder $query) => $query->where('request.member_id', '!=', $user->member_id));

        return [
            $this->loanReviews($user),
            $loans->where('loan.status', 'approved')
                ->join('member_exits as exit', fn ($join) => $join->on('exit.member_id', '=', 'loan.member_id')->where('exit.status', 'approved'))
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('loan_disbursements as transfer')
                    ->whereColumn('transfer.loan_application_id', 'loan.id'))
                ->selectRaw("'loan-exit' AS type, loan.id::text AS reference, loan.member_id, exit.reviewed_at AS since"),
            DB::table('loan_batches')->where('status', 'prepared')->where('prepared_by', '!=', $user->id)
                ->selectRaw("'batch-review' AS type, id::text AS reference, null::bigint AS member_id, prepared_at AS since"),
            $withdrawals->selectRaw("'withdrawal-review' AS type, request.id::text AS reference, request.member_id, request.requested_at AS since"),
            $exits->selectRaw("'exit-review' AS type, request.id::text AS reference, request.member_id, request.requested_at AS since"),
        ];
    }

    private function loanReviews(User $user): Builder
    {
        return $this->approval->forReviewer(DB::table('loan_applications as loan'), $user, 'loan')
            ->where('loan.status', 'submitted')
            ->selectRaw("'loan-review' AS type, loan.id::text AS reference, loan.member_id, coalesce(loan.recommended_at, loan.created_at) AS since");
    }

    private function adminTasks(): array
    {
        $confirmed = DB::table('loan_batches as batch')->where('batch.status', 'confirmed');
        $statementExceptions = DB::table('monthly_member_statements as statement')->join('members as member', 'member.id', '=', 'statement.member_id')
            ->where(function (Builder $query): void {
                $query->where('statement.delivery_status', 'needs_review')
                    ->orWhere(fn (Builder $pending) => $pending->where('statement.delivery_status', 'pending')
                        ->whereRaw('lower(member.email COLLATE "C") IS DISTINCT FROM statement.recipient'));
            });
        $lastMonth = now('Africa/Dar_es_Salaam')->startOfMonth()->subMonth()->toDateString();

        return [
            DB::table('loan_applications as loan')
                ->where($this->sponsorship->confirmedActiveCount('loan'), '=', LoanSponsorship::RequiredCount)
                ->where('loan.status', 'submitted')->whereIn('loan.approval_lane', LoanApproval::PendingLanes)
                ->selectRaw("'loan-recommendation' AS type, loan.id::text AS reference, loan.member_id, loan.created_at AS since"),
            DB::table('loan_applications as loan')->leftJoin('loan_application_decisions as decision', 'decision.loan_application_id', '=', 'loan.id')
                ->where('loan.status', 'approved')
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('member_exits as exit')
                    ->whereColumn('exit.member_id', 'loan.member_id')->whereIn('exit.status', ['requested', 'approved']))
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('loan_batches as batch')
                    ->where('batch.kind', 'disbursement')->whereIn('batch.status', ['prepared', 'approved'])
                    ->whereRaw("batch.items @> jsonb_build_array(jsonb_build_object('loan_id', loan.id))"))
                ->selectRaw("'loan-disbursement' AS type, loan.id::text AS reference, loan.member_id, coalesce(decision.reviewed_at, loan.created_at) AS since"),
            DB::table('loan_batches')->where('status', 'approved')
                ->selectRaw("CASE WHEN kind = 'repayment' THEN 'payroll-confirmation' ELSE 'disbursement-confirmation' END AS type,
                    id::text AS reference, null::bigint AS member_id, reviewed_at AS since"),
            (clone $confirmed)->where('batch.kind', 'disbursement')
                ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('loan_disbursements as transfer')->whereColumn('transfer.loan_batch_id', 'batch.id'))
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('loan_journal_postings as posting')->whereColumn('posting.loan_batch_id', 'batch.id'))
                ->selectRaw("'disbursement-journal' AS type, batch.id::text AS reference, null::bigint AS member_id, batch.confirmed_at AS since"),
            (clone $confirmed)->where('batch.kind', 'savings_payment')
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('loan_journal_postings as posting')->whereColumn('posting.loan_batch_id', 'batch.id'))
                ->selectRaw("'savings-payment-journal' AS type, batch.id::text AS reference, null::bigint AS member_id, batch.confirmed_at AS since"),
            $confirmed->where('batch.kind', 'repayment')->where(function (Builder $query): void {
                $query->where(fn (Builder $loan) => $loan
                    ->whereExists(fn (Builder $receipt) => $receipt->selectRaw('1')->from('loan_repayment_receipts as receipt')->whereColumn('receipt.loan_batch_id', 'batch.id'))
                    ->whereNotExists(fn (Builder $posting) => $posting->selectRaw('1')->from('loan_journal_postings as posting')->whereColumn('posting.loan_batch_id', 'batch.id')))
                    ->orWhereExists(fn (Builder $funds) => $funds->selectRaw('1')->from('member_fund_transactions as fund')
                        ->whereColumn('fund.loan_batch_id', 'batch.id')
                        ->whereNotExists(fn (Builder $posting) => $posting->selectRaw('1')->from('member_fund_journals as posting')
                            ->whereColumn('posting.member_fund_transaction_id', 'fund.id')));
            })->selectRaw("'payroll-journal' AS type, batch.id::text AS reference, null::bigint AS member_id, batch.confirmed_at AS since"),
            DB::table('member_savings_withdrawals')->where('status', 'approved')
                ->selectRaw("'withdrawal-pay' AS type, id::text AS reference, member_id, reviewed_at AS since"),
            DB::table('member_exits')->where('status', 'approved')
                ->selectRaw("'exit-finalize' AS type, id::text AS reference, member_id, reviewed_at AS since"),
            DB::table('member_fund_transactions as fund')->whereNull('fund.loan_batch_id')
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('member_fund_journals as posting')
                    ->whereColumn('posting.member_fund_transaction_id', 'fund.id'))
                ->selectRaw("'fund-journal' AS type, fund.id::text AS reference, fund.member_id, fund.recorded_at AS since"),
            $statementExceptions->groupBy('statement.period')
                ->selectRaw("'statement-delivery' AS type, to_char(statement.period, 'YYYY-MM') AS reference, null::bigint AS member_id,
                    coalesce(min(statement.attempted_at), min(statement.created_at), statement.period::timestamptz) AS since"),
            DB::query()->fromRaw(<<<'SQL'
ledger_opening_journals AS journal
CROSS JOIN LATERAL generate_series(date_trunc('month', journal.effective_on::timestamp), ?::timestamp, interval '1 month') AS month(period)
SQL, [$lastMonth])
                ->whereRaw("coalesce((SELECT event.action FROM ledger_period_events AS event WHERE event.period = month.period::date ORDER BY event.id DESC LIMIT 1), '') <> 'closed'")
                ->selectRaw("'period-close' AS type, to_char(month.period, 'YYYY-MM') AS reference, null::bigint AS member_id,
                    (month.period + interval '1 month') AT TIME ZONE 'Africa/Dar_es_Salaam' AS since"),
        ];
    }
}
