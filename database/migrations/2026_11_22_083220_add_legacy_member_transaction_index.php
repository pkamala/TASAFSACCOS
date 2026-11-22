<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE INDEX legacy_member_transactions_idx
ON legacy_onboarding_rows (source_sha256, source_table, (data->>'member_id'))
WHERE source_table IN ('membersavingaccounts', 'membershareaccounts', 'monthpaymentlists')
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX legacy_member_transactions_idx');
    }
};
