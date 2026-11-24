<?php

namespace App;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class MemberNotifications
{
    public function pendingSponsorships(int $member): Builder
    {
        return DB::table('loan_sponsorships as sponsorship')
            ->join('loan_applications as loan', 'loan.id', '=', 'sponsorship.loan_application_id')
            ->join('members as sponsor', 'sponsor.id', '=', 'sponsorship.member_id')
            ->where('sponsorship.member_id', $member)->where('sponsor.status', 'active')
            ->whereNull('sponsorship.confirmed_at')->where('loan.status', 'submitted')
            ->where(fn (Builder $pending) => $pending->whereIn('loan.approval_lane', LoanApproval::PendingLanes)->orWhereNull('loan.approval_lane'));
    }

    public function updates(int $member): Builder
    {
        $sponsorships = $this->pendingSponsorships($member)
            ->selectRaw("'sponsorship' as kind, loan.id as reference, 'pending' as status, loan.created_at as occurred_at");
        $loans = DB::table('loan_applications as loan')->where('loan.member_id', $member)
            ->leftJoin('loan_application_decisions as decision', 'decision.loan_application_id', '=', 'loan.id')
            ->leftJoin('loan_disbursements as transfer', 'transfer.loan_application_id', '=', 'loan.id')
            ->leftJoin('loan_exit_cancellations as cancellation', 'cancellation.loan_application_id', '=', 'loan.id')
            ->whereIn('loan.status', ['approved', 'declined', 'disbursed', 'repaid', 'cancelled'])
            ->selectRaw("'loan' as kind, loan.id as reference, loan.status,
                coalesce(cancellation.cancelled_at,
                    CASE WHEN loan.status = 'repaid' THEN (
                        select max(receipt.recorded_at) from loan_repayment_receipts as receipt where receipt.loan_application_id = loan.id
                    ) END, transfer.recorded_at, decision.reviewed_at, loan.created_at) as occurred_at");
        $withdrawals = DB::table('member_savings_withdrawals as withdrawal')->where('withdrawal.member_id', $member)
            ->leftJoin('member_fund_transactions as payment', 'payment.member_savings_withdrawal_id', '=', 'withdrawal.id')
            ->whereIn('withdrawal.status', ['approved', 'declined', 'paid'])
            ->selectRaw("'withdrawal' as kind, withdrawal.id as reference, withdrawal.status,
                coalesce(payment.recorded_at, withdrawal.reviewed_at, withdrawal.requested_at) as occurred_at");

        return DB::query()->fromSub($sponsorships->unionAll($loans)->unionAll($withdrawals), 'notification');
    }
}
