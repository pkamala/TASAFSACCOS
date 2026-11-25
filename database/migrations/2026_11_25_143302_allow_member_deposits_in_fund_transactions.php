<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceConstraint("('savings_deposit', 'share_purchase', 'member_deposit')");
    }

    public function down(): void
    {
        $this->replaceConstraint("('savings_deposit', 'share_purchase')");
    }

    private function replaceConstraint(string $receiptKinds): void
    {
        DB::statement('ALTER TABLE member_fund_transactions DROP CONSTRAINT member_fund_transaction_kind_check');
        DB::statement(<<<SQL
ALTER TABLE member_fund_transactions ADD CONSTRAINT member_fund_transaction_kind_check CHECK (
    (kind IN {$receiptKinds} AND signed_minor > 0 AND member_savings_withdrawal_id IS NULL)
    OR (kind = 'savings_withdrawal' AND signed_minor < 0 AND member_savings_withdrawal_id IS NOT NULL)
)
SQL);
    }
};
