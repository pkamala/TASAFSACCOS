<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_book_accounts', function (Blueprint $table): void {
            $table->boolean('is_bank_account')->default(false);
        });
        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
            $table->date('period');
            $table->bigInteger('statement_minor');
            $table->bigInteger('book_minor');
            $table->bigInteger('outstanding_deposits_minor');
            $table->bigInteger('outstanding_payments_minor');
            $table->jsonb('cleared_keys');
            $table->jsonb('outstanding_lines');
            $table->char('ledger_sha256', 64);
            $table->string('statement_reference', 100);
            $table->string('review_note', 500)->nullable();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at');
            $table->index(['period', 'chart_account_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_bank_reconciliation_rewrite() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Bank reconciliation reviews cannot be rewritten' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER bank_reconciliation_append_only BEFORE UPDATE OR DELETE ON bank_reconciliations
FOR EACH ROW EXECUTE FUNCTION reject_bank_reconciliation_rewrite();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
        DB::statement('DROP FUNCTION reject_bank_reconciliation_rewrite()');
        Schema::table('cash_book_accounts', function (Blueprint $table): void {
            $table->dropColumn('is_bank_account');
        });
    }
};
