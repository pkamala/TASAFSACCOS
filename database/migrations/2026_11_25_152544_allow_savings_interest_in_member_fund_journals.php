<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_fund_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('withholding_minor')->default(0);
        });
        Schema::table('member_fund_journals', function (Blueprint $table): void {
            $table->unsignedBigInteger('withholding_minor')->default(0);
            $table->foreignId('withholding_account_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
        });
        $this->replaceKindConstraint(true);
        DB::statement("ALTER TABLE member_fund_transactions ADD CONSTRAINT member_fund_withholding_check CHECK (
            withholding_minor = 0 OR (kind = 'savings_interest' AND withholding_minor BETWEEN 1 AND signed_minor)
        )");
        DB::statement('ALTER TABLE member_fund_journals ADD CONSTRAINT member_fund_journal_withholding_check CHECK (
            (withholding_minor = 0 AND withholding_account_id IS NULL)
            OR (withholding_minor BETWEEN 1 AND amount_minor AND withholding_account_id IS NOT NULL
                AND withholding_account_id NOT IN (debit_account_id, credit_account_id))
        )');
    }

    public function down(): void
    {
        $this->replaceKindConstraint(false);
        DB::statement('ALTER TABLE member_fund_transactions DROP CONSTRAINT member_fund_withholding_check');
        DB::statement('ALTER TABLE member_fund_journals DROP CONSTRAINT member_fund_journal_withholding_check');
        Schema::table('member_fund_transactions', function (Blueprint $table): void {
            $table->dropColumn('withholding_minor');
        });
        Schema::table('member_fund_journals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('withholding_account_id');
            $table->dropColumn('withholding_minor');
        });
    }

    private function replaceKindConstraint(bool $allowSavingsInterest): void
    {
        $interest = $allowSavingsInterest ? "OR (kind = 'savings_interest' AND signed_minor > 0 AND member_savings_withdrawal_id IS NULL AND loan_batch_id IS NULL)" : '';
        DB::statement('ALTER TABLE member_fund_transactions DROP CONSTRAINT member_fund_transaction_kind_check');
        DB::statement(<<<SQL
ALTER TABLE member_fund_transactions ADD CONSTRAINT member_fund_transaction_kind_check CHECK (
    (kind IN ('savings_deposit', 'share_purchase', 'member_deposit', 'deposit_interest') AND signed_minor > 0 AND member_savings_withdrawal_id IS NULL)
    OR (kind = 'savings_withdrawal' AND signed_minor < 0 AND member_savings_withdrawal_id IS NOT NULL)
    OR (kind = 'savings_loan_payment' AND signed_minor < 0 AND member_savings_withdrawal_id IS NULL AND loan_batch_id IS NOT NULL)
    {$interest}
)
SQL);
    }
};
