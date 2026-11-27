<?php

namespace App;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class MemberFunds
{
    private const SavingsKinds = ['savings_deposit', 'savings_withdrawal', 'savings_loan_payment'];

    /** @return array<string, int> */
    public function monthlySavingsMovements(int $member, CarbonImmutable $openingDate, CarbonImmutable $before): array
    {
        return DB::table('member_fund_transactions')->where('member_id', $member)
            ->whereIn('kind', self::SavingsKinds)
            ->where('effective_on', '>=', $openingDate->toDateString())
            ->where('effective_on', '<', $before->toDateString())
            ->selectRaw("to_char(effective_on, 'YYYY-MM') AS month, sum(signed_minor) AS movement")
            ->groupBy('month')->pluck('movement', 'month')
            ->map(static fn ($movement): int => (int) $movement)->all();
    }

    public function savingsPrincipalAt(int $member, int $openingSavings, CarbonImmutable $openingDate, CarbonImmutable $monthEnd): int
    {
        return $openingSavings + (int) DB::table('member_fund_transactions')->where('member_id', $member)
            ->whereIn('kind', self::SavingsKinds)
            ->whereBetween('effective_on', [$openingDate->toDateString(), $monthEnd->toDateString()])
            ->sum('signed_minor');
    }

    public static function savingsMovement(bool $historical = false): string
    {
        $posting = self::activePostedJournal($historical);

        return "sum(CASE
            WHEN kind IN ('savings_deposit', 'savings_withdrawal', 'savings_loan_payment', 'exit_savings') THEN signed_minor
            WHEN kind = 'savings_interest' AND {$posting} THEN signed_minor - withholding_minor
            ELSE 0 END)";
    }

    private static function activePostedJournal(bool $historical = false): string
    {
        $reversalDate = $historical ? 'AND reversal.transaction_date <= ?' : '';

        return "EXISTS (SELECT 1 FROM member_fund_journals AS journal
            WHERE journal.member_fund_transaction_id = member_fund_transactions.id
            AND NOT EXISTS (SELECT 1 FROM ledger_journal_reversals AS reversal
                WHERE reversal.member_fund_journal_id = journal.id {$reversalDate}))";
    }

    public function balances(int $member, bool $includeInterestMonths = false, ?string $asOf = null): array
    {
        $opening = $this->opening()->where('member_id', $member)
            ->when($asOf !== null, fn (Builder $query) => $query->where('as_of', '<=', $asOf))->first();
        $movements = $this->movements($asOf)->where('member_id', $member);
        if ($includeInterestMonths) {
            $posted = self::activePostedJournal($asOf !== null);
            $movements->selectRaw("json_agg(to_char(effective_on, 'YYYY-MM'))
                FILTER (WHERE kind = 'savings_interest' AND {$posted}) AS posted_interest_months", $asOf === null ? [] : [$asOf]);
        }
        $transactions = $movements->first();
        $reserved = (int) $this->reservations($asOf)->where('member_id', $member)->first()->reserved;
        $savings = $opening === null ? null : $opening->savings_minor + (int) $transactions->savings;
        $shares = $opening === null ? null : $opening->shares_minor + (int) $transactions->shares;

        return [
            'opening' => $opening, 'savings' => $savings, 'shares' => $shares, 'deposits' => (int) $transactions->deposits,
            'reserved' => $reserved, 'available' => $savings === null ? null : $savings - $reserved,
            'latest' => $transactions->latest,
            'postedInterestMonths' => array_fill_keys(json_decode($transactions->posted_interest_months ?? '[]', true), true),
        ];
    }

    public function withBalances(Builder $members, ?string $asOf = null): Builder
    {
        return $members
            ->leftJoinLateral($this->opening()->whereColumn('member_id', 'members.id')->when($asOf, fn (Builder $query) => $query->where('as_of', '<=', $asOf))->limit(1), 'opening')
            ->leftJoinLateral($this->movements($asOf)->whereColumn('member_id', 'members.id'), 'movement')
            ->leftJoinLateral($this->reservations($asOf)->whereColumn('member_id', 'members.id'), 'withdrawal')
            ->addSelect('opening.as_of', 'withdrawal.reserved', 'movement.deposits')
            ->selectRaw('opening.shares_minor + movement.shares AS shares')
            ->selectRaw('opening.savings_minor + movement.savings AS savings, opening.savings_minor + movement.savings - withdrawal.reserved AS available');
    }

    private function opening(): Builder
    {
        return DB::table('member_verified_balances')->whereNull('superseded_by_id')->orderByDesc('as_of')->orderByDesc('id');
    }

    private function movements(?string $asOf = null): Builder
    {
        $historical = $asOf !== null;
        $bindings = $historical ? [$asOf] : [];
        $posted = self::activePostedJournal($historical);

        return DB::table('member_fund_transactions')
            ->when($historical, fn (Builder $query) => $query->where('effective_on', '<=', $asOf))
            ->selectRaw('coalesce('.self::savingsMovement($historical).', 0) AS savings', $bindings)
            ->selectRaw("coalesce(sum(signed_minor) FILTER (WHERE kind IN ('share_purchase', 'exit_shares')), 0) AS shares")
            ->selectRaw("coalesce(sum(signed_minor) FILTER (WHERE kind = 'member_deposit' OR (kind = 'deposit_interest' AND {$posted})), 0) AS deposits", $bindings)
            ->selectRaw("max(effective_on) FILTER (WHERE kind NOT IN ('member_deposit', 'deposit_interest', 'savings_interest')) AS latest");
    }

    private function reservations(?string $asOf = null): Builder
    {
        return DB::table('member_savings_withdrawals as withdrawal')
            ->selectRaw('coalesce(sum(amount_minor), 0) AS reserved')
            ->when($asOf === null, fn (Builder $query) => $query->where('status', 'approved'))
            ->when($asOf !== null, function (Builder $query) use ($asOf): void {
                $query->whereIn('status', ['approved', 'paid'])
                    ->whereRaw("(reviewed_at AT TIME ZONE 'Africa/Dar_es_Salaam')::date <= ?", [$asOf])
                    ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('member_fund_transactions as payment')
                        ->whereColumn('payment.member_savings_withdrawal_id', 'withdrawal.id')
                        ->where('payment.effective_on', '<=', $asOf));
            });
    }
}
