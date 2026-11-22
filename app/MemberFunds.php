<?php

namespace App;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class MemberFunds
{
    public function balances(int $member): array
    {
        $opening = $this->opening()->where('member_id', $member)->first();
        $transactions = $this->movements()->where('member_id', $member)->first();
        $reserved = (int) $this->reservations()->where('member_id', $member)->first()->reserved;
        $savings = $opening === null ? null : $opening->savings_minor + (int) $transactions->savings;
        $shares = $opening === null ? null : $opening->shares_minor + (int) $transactions->shares;

        return [
            'opening' => $opening, 'savings' => $savings, 'shares' => $shares,
            'reserved' => $reserved, 'available' => $savings === null ? null : $savings - $reserved,
            'latest' => $transactions->latest,
        ];
    }

    public function withSavings(Builder $members): Builder
    {
        return $members
            ->leftJoinLateral($this->opening()->whereColumn('member_id', 'members.id')->limit(1), 'opening')
            ->leftJoinLateral($this->movements()->whereColumn('member_id', 'members.id'), 'movement')
            ->leftJoinLateral($this->reservations()->whereColumn('member_id', 'members.id'), 'withdrawal')
            ->addSelect('opening.as_of', 'withdrawal.reserved')
            ->selectRaw('opening.savings_minor + movement.savings AS savings, opening.savings_minor + movement.savings - withdrawal.reserved AS available');
    }

    private function opening(): Builder
    {
        return DB::table('member_verified_balances')->orderByDesc('as_of')->orderByDesc('id');
    }

    private function movements(): Builder
    {
        return DB::table('member_fund_transactions')
            ->selectRaw("coalesce(sum(signed_minor) FILTER (WHERE kind <> 'share_purchase'), 0) AS savings")
            ->selectRaw("coalesce(sum(signed_minor) FILTER (WHERE kind = 'share_purchase'), 0) AS shares")
            ->selectRaw('max(effective_on) AS latest');
    }

    private function reservations(): Builder
    {
        return DB::table('member_savings_withdrawals')->where('status', 'approved')
            ->selectRaw('coalesce(sum(amount_minor), 0) AS reserved');
    }
}
