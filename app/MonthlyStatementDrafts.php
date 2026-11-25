<?php

namespace App;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MonthlyStatementDrafts
{
    public function source(string $start): array
    {
        $end = CarbonImmutable::parse($start, 'Africa/Dar_es_Salaam')->endOfMonth()->toDateString();
        $rows = $this->forMonth($start, $end)->whereNull('hold')->map($this->reviewRow(...))->values()->all();

        return [hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), $rows];
    }

    public function reviewRow(object $row): array
    {
        return [
            'registration_no' => $row->registration_no, 'email' => $row->email,
            'loan_balance' => $this->decimal($row->loan_minor),
            'monthly_contribution' => $this->decimal($row->contribution_minor),
            'savings_balance' => $this->decimal($row->savings_minor),
        ];
    }

    public function forMonth(string $start, string $end): Collection
    {
        $opening = DB::table('member_verified_balances')->whereNull('superseded_by_id')
            ->selectRaw('distinct on (member_id) member_id, as_of, savings_minor')
            ->orderBy('member_id')->orderByDesc('as_of')->orderByDesc('id');
        $funds = DB::table('member_fund_transactions')->where('effective_on', '<=', $end)->groupBy('member_id')
            ->select('member_id')
            ->selectRaw(MemberFunds::savingsMovement(historical: true).' AS savings_movement', [$end])
            ->selectRaw("sum(signed_minor) FILTER (WHERE effective_on >= ? AND kind IN ('savings_deposit', 'share_purchase')) AS contributions", [$start]);
        $principalPaid = DB::table('loan_repayment_receipts as receipt')
            ->join('loan_repayment_allocations as allocation', 'allocation.loan_repayment_receipt_id', '=', 'receipt.id')
            ->where('receipt.received_on', '<=', $end)->groupBy('receipt.loan_application_id')
            ->selectRaw('receipt.loan_application_id, sum(allocation.principal_minor) AS paid_minor');
        $loans = DB::table('loan_applications as loan')
            ->leftJoin('loan_disbursements as transfer', 'transfer.loan_application_id', '=', 'loan.id')
            ->leftJoin('active_loan_migrations as migration', 'migration.loan_application_id', '=', 'loan.id')
            ->leftJoinSub($principalPaid, 'paid', 'paid.loan_application_id', '=', 'loan.id')
            ->whereRaw('coalesce(transfer.transferred_on, migration.effective_on) <= ?', [$end])->groupBy('loan.member_id')->select('loan.member_id')
            ->selectRaw('sum(loan.principal_minor + loan.insurance_minor + loan.processing_fee_minor + loan.other_charges_minor - coalesce(paid.paid_minor, 0)) AS loan_minor');

        $legacy = DB::table('legacy_onboarding_rows as staged')->where('staged.source_table', 'loans')
            ->join('members as owner', function ($join): void {
                $join->on('owner.legacy_source_sha256', '=', 'staged.source_sha256')
                    ->whereRaw("owner.legacy_member_id::text = staged.data->>'member_id'");
            })
            ->leftJoin('verified_legacy_loan_balances as verified', function ($join) use ($end): void {
                $join->on('verified.source_sha256', '=', 'staged.source_sha256')
                    ->on('verified.source_id', '=', 'staged.source_id')->on('verified.member_id', '=', 'owner.id')
                    ->where('verified.as_of', '=', $end);
            })
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('active_loan_migrations as migrated')
                ->whereColumn('migrated.source_sha256', 'staged.source_sha256')->whereColumn('migrated.source_id', 'staged.source_id')
                ->where('migrated.effective_on', '<=', $end))
            ->whereRaw(<<<'SQL'
NOT coalesce(staged.data->>'disbursement_date' ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
    AND pg_input_is_valid(staged.data->>'disbursement_date', 'date')
    AND staged.data->>'disbursement_date' > ?, false)
SQL, [$end])
            ->groupBy('owner.id')->selectRaw('owner.id AS member_id, count(*) AS expected, count(verified.id) AS verified, sum(verified.principal_minor) AS principal_minor');

        return DB::table('members as member')->where('member.status', 'active')
            ->leftJoinSub($opening, 'opening', 'opening.member_id', '=', 'member.id')
            ->leftJoinSub($funds, 'funds', 'funds.member_id', '=', 'member.id')
            ->leftJoinSub($loans, 'loans', 'loans.member_id', '=', 'member.id')
            ->leftJoinSub($legacy, 'legacy', 'legacy.member_id', '=', 'member.id')
            ->select(['member.registration_no', 'member.first_name', 'member.last_name', 'member.email', 'opening.as_of'])
            ->selectRaw('opening.savings_minor + coalesce(funds.savings_movement, 0) AS savings_minor')
            ->selectRaw('coalesce(funds.contributions, 0) AS contribution_minor, coalesce(loans.loan_minor, 0) + coalesce(legacy.principal_minor, 0) AS loan_minor')
            ->selectRaw(<<<'SQL'
member.legacy_member_id IS NOT NULL AND (
    NOT EXISTS (
        SELECT 1 FROM legacy_onboarding_rows AS staged
        WHERE staged.source_sha256 = member.legacy_source_sha256 AND staged.source_table = 'loans'
    ) OR coalesce(legacy.expected, 0) > coalesce(legacy.verified, 0)
) AS legacy_loan_review
SQL)
            ->orderByRaw('lower(member.first_name), lower(member.last_name)')->orderBy('member.id')->get()
            ->each(function ($row) use ($start): void {
                $row->hold = match (true) {
                    $row->legacy_loan_review => 'Legacy loans need reconciliation',
                    $row->as_of === null || $row->as_of >= $start => 'Verified opening balances must predate the month',
                    ! $this->validContact($row->registration_no, $row->email) => 'Check the membership number or email',
                    min($row->loan_minor, $row->contribution_minor, $row->savings_minor) < 0
                        || max($row->loan_minor, $row->contribution_minor, $row->savings_minor) > 99999999999999 => 'Review the calculated amounts',
                    default => null,
                };
            });
    }

    private function validContact(string $registrationNo, string $email): bool
    {
        return preg_match('/\A[A-Z0-9][A-Z0-9-]{0,29}\z/', $registrationNo) === 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && ! str_contains('=+-@', $email[0]);
    }

    private function decimal(int|string $minor): string
    {
        $minor = (int) $minor;

        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
