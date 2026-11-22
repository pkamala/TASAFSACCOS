<?php

namespace App;

use Illuminate\Support\Facades\DB;

class MemberFunds
{
    public function balances(int $member): array
    {
        $opening = DB::table('member_verified_balances')->where('member_id', $member)
            ->orderByDesc('as_of')->orderByDesc('id')->first();
        $transactions = DB::table('member_fund_transactions')->where('member_id', $member)
            ->selectRaw("coalesce(sum(signed_minor) FILTER (WHERE kind <> 'share_purchase'), 0) AS savings")
            ->selectRaw("coalesce(sum(signed_minor) FILTER (WHERE kind = 'share_purchase'), 0) AS shares")
            ->selectRaw('max(effective_on) AS latest')->first();
        $reserved = (int) DB::table('member_savings_withdrawals')->where('member_id', $member)
            ->where('status', 'approved')->sum('amount_minor');
        $savings = $opening === null ? null : $opening->savings_minor + (int) $transactions->savings;
        $shares = $opening === null ? null : $opening->shares_minor + (int) $transactions->shares;

        return [
            'opening' => $opening, 'savings' => $savings, 'shares' => $shares,
            'reserved' => $reserved, 'available' => $savings === null ? null : $savings - $reserved,
            'latest' => $transactions->latest,
        ];
    }
}
