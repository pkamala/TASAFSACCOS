<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_exits', function (Blueprint $table): void {
            $table->foreignId('settlement_journal_id')->nullable()->unique()->constrained('general_journals')->restrictOnDelete();
            $table->foreignId('settlement_batch_id')->nullable()->unique()->constrained('loan_batches')->restrictOnDelete();
        });
        $this->replaceKindConstraint(true);
    }

    public function down(): void
    {
        $this->replaceKindConstraint(false);
        Schema::table('member_exits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('settlement_journal_id');
            $table->dropConstrainedForeignId('settlement_batch_id');
        });
    }

    private function replaceKindConstraint(bool $allowExit): void
    {
        $exit = $allowExit ? "OR (kind IN ('exit_savings', 'exit_shares') AND signed_minor < 0 AND member_savings_withdrawal_id IS NULL AND loan_batch_id IS NOT NULL)" : '';
        DB::statement('ALTER TABLE member_fund_transactions DROP CONSTRAINT member_fund_transaction_kind_check');
        DB::statement(<<<SQL
ALTER TABLE member_fund_transactions ADD CONSTRAINT member_fund_transaction_kind_check CHECK (
    (kind IN ('savings_deposit', 'share_purchase', 'member_deposit', 'deposit_interest') AND signed_minor > 0 AND member_savings_withdrawal_id IS NULL)
    OR (kind = 'savings_withdrawal' AND signed_minor < 0 AND member_savings_withdrawal_id IS NOT NULL)
    OR (kind = 'savings_loan_payment' AND signed_minor < 0 AND member_savings_withdrawal_id IS NULL AND loan_batch_id IS NOT NULL)
    OR (kind = 'savings_interest' AND signed_minor > 0 AND member_savings_withdrawal_id IS NULL AND loan_batch_id IS NULL)
    {$exit}
)
SQL);
    }
};
