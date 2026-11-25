<?php

namespace App;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MemberExitSettlement
{
    public function __construct(private MemberFunds $funds, private ExitSettlementRates $rates) {}

    /** @return array{savings: int, shares: int, fee: int, principal: int, loanInterest: int, subtotal: int, interest: array{gross: int, withholding: int, net: int, payout: int, annualRate: int, withholdingRate: int}}|null */
    public function draft(int $member, CarbonImmutable $date): ?array
    {
        $balance = $this->funds->balances($member, includeInterestMonths: true);
        if ($balance['opening'] === null || $balance['opening']->as_of > $date->toDateString()) {
            return null;
        }

        $savings = $balance['savings'];
        $shares = $balance['shares'];
        $fee = intdiv(max(0, $savings) + 25, 50);

        $paid = DB::table('loan_repayment_allocations as allocation')
            ->join('loan_applications as loan', 'loan.id', '=', 'allocation.loan_application_id')
            ->where('loan.member_id', $member)->where('loan.status', 'disbursed')
            ->groupBy('allocation.loan_application_id', 'allocation.installment_number')
            ->select('allocation.loan_application_id', 'allocation.installment_number')
            ->selectRaw('sum(allocation.principal_minor) AS principal, sum(allocation.interest_minor) AS interest');

        $outstanding = DB::table('loan_application_installments as installment')
            ->join('loan_applications as loan', 'loan.id', '=', 'installment.loan_application_id')
            ->leftJoinSub($paid, 'paid', fn ($join) => $join
                ->on('paid.loan_application_id', '=', 'installment.loan_application_id')
                ->on('paid.installment_number', '=', 'installment.number'))
            ->where('loan.member_id', $member)->where('loan.status', 'disbursed')
            ->selectRaw('coalesce(sum(installment.principal_minor - coalesce(paid.principal, 0)), 0) AS principal')
            ->selectRaw('coalesce(sum(installment.interest_minor - coalesce(paid.interest, 0))
                FILTER (WHERE installment.due_date <= ?), 0) AS interest', [
                $date->endOfMonth()->toDateString(),
            ])->first();

        $principal = (int) $outstanding->principal;
        $loanInterest = (int) $outstanding->interest;
        $subtotal = $savings - $fee - $principal - $loanInterest + $shares;
        $openingDate = CarbonImmutable::parse($balance['opening']->as_of, $date->getTimezone());
        $interest = $this->interest($member, $openingDate, $date, $balance['opening']->savings_minor, $subtotal, $balance['postedInterestMonths']);

        return compact('savings', 'shares', 'fee', 'principal', 'loanInterest', 'subtotal', 'interest');
    }

    public function deactivateMember(int $member, int $exit, int $actor): void
    {
        $now = now();
        DB::table('members')->where('id', $member)->update(['status' => 'exited', 'updated_at' => $now]);
        $officer = User::query()->where('member_id', $member)->first();
        if ($officer?->isBoardOfficer()) {
            DB::table('board_role_changes')->insert([
                'role' => $officer->board_role, 'previous_user_id' => $officer->id, 'next_user_id' => null,
                'changed_by' => $actor, 'reason' => "Member exit $exit finalized", 'changed_at' => $now,
            ]);
            DB::table('users')->where('id', $officer->id)->update(['board_role' => null, 'updated_at' => $now]);
        }
    }

    /**
     * @param  array<string, true>  $postedMonths
     * @return array{gross: int, withholding: int, net: int, payout: int, annualRate: int, withholdingRate: int}
     */
    private function interest(int $member, CarbonImmutable $openingDate, CarbonImmutable $date, int $savings, int $subtotal, array $postedMonths): array
    {
        $rates = $this->rates->current();
        $annualRate = $rates->savings_interest_basis_points;
        $withholdingRate = $rates->withholding_basis_points;
        $periodEnd = $date->startOfMonth();
        $firstMonthOfYear = $date->startOfYear();
        $movements = $this->funds->monthlySavingsMovements($member, $openingDate, $periodEnd);
        $gross = 0;
        $withholding = 0;
        for ($month = $openingDate->startOfMonth(); $month < $periodEnd; $month = $month->addMonth()) {
            $monthKey = $month->format('Y-m');
            $savings += $movements[$monthKey] ?? 0;
            if ($month >= $firstMonthOfYear && ! isset($postedMonths[$monthKey])) {
                $interest = SavingsInterest::month($savings, $annualRate);
                $gross += $interest;
                $withholding += SavingsInterest::withholding($interest, $withholdingRate);
            }
        }
        $net = $gross - $withholding;
        $payout = $subtotal + $net;

        return compact('gross', 'withholding', 'net', 'payout', 'annualRate', 'withholdingRate');
    }
}
