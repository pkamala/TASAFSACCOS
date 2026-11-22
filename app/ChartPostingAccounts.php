<?php

namespace App;

use Illuminate\Support\Facades\DB;

class ChartPostingAccounts
{
    public function available(array $ids): bool
    {
        $query = DB::table('chart_accounts')->whereIn('id', $ids)->where('is_ledger_account', true)
            ->whereNull('retired_at');

        if (DB::transactionLevel() > 0) {
            $query->orderBy('id')->sharedLock();
        }

        return $query->get(['id'])->count() === count($ids);
    }
}
