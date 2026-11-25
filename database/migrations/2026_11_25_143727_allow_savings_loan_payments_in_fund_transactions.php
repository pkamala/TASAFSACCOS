<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceConstraint(true);
    }

    public function down(): void
    {
        $this->replaceConstraint(false);
    }

    private function replaceConstraint(bool $allowSavingsPayments): void
    {
        DB::statement('ALTER TABLE member_fund_transactions DROP CONSTRAINT member_fund_transaction_kind_check');
        $savingsPayment = $allowSavingsPayments ? " OR (kind = 'savings_loan_payment' AND signed_minor < 0 AND member_savings_withdrawal_id IS NULL AND loan_batch_id IS NOT NULL)" : '';
        DB::statement(<<<SQL
ALTER TABLE member_fund_transactions ADD CONSTRAINT member_fund_transaction_kind_check CHECK (
    (kind IN ('savings_deposit', 'share_purchase', 'member_deposit', 'deposit_interest') AND signed_minor > 0 AND member_savings_withdrawal_id IS NULL)
    OR (kind = 'savings_withdrawal' AND signed_minor < 0 AND member_savings_withdrawal_id IS NOT NULL)
    {$savingsPayment}
)
SQL);
    }
};
