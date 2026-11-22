<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_savings_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 16);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->index(['member_id', 'status']);
        });

        Schema::create('member_fund_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_verified_balance_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_savings_withdrawal_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->bigInteger('signed_minor');
            $table->date('effective_on');
            $table->string('reference', 100);
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->unique(['member_id', 'kind', 'reference'], 'member_fund_transaction_reference_unique');
            $table->index(['member_id', 'effective_on', 'id']);
        });

        DB::statement(<<<'SQL'
ALTER TABLE member_fund_transactions ADD CONSTRAINT member_fund_transaction_kind_check CHECK (
    (kind IN ('savings_deposit', 'share_purchase') AND signed_minor > 0 AND member_savings_withdrawal_id IS NULL)
    OR (kind = 'savings_withdrawal' AND signed_minor < 0 AND member_savings_withdrawal_id IS NOT NULL)
)
SQL);

        Schema::table('loan_applications', function (Blueprint $table): void {
            $table->unsignedBigInteger('eligible_savings_minor')->nullable();
            $table->unsignedBigInteger('eligible_shares_minor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', fn (Blueprint $table) => $table->dropColumn(['eligible_savings_minor', 'eligible_shares_minor']));
        Schema::dropIfExists('member_fund_transactions');
        Schema::dropIfExists('member_savings_withdrawals');
    }
};
