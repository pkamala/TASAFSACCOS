<?php

namespace App;

use Illuminate\Support\Facades\DB;
use stdClass;

class ExitSettlementRates
{
    public function current(): stdClass
    {
        return DB::table('exit_settlement_rate_revisions')->orderByDesc('id')->first()
            ?? (object) ['id' => 0, 'savings_interest_basis_points' => 600, 'withholding_basis_points' => 1000];
    }
}
