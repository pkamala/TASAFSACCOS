<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_fund_journals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_fund_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('debit_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('credit_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->date('transaction_date');
            $table->string('source_reference', 100);
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at');
            $table->index(['debit_account_id', 'transaction_date']);
            $table->index(['credit_account_id', 'transaction_date']);
        });

        DB::statement('ALTER TABLE member_fund_journals ADD CONSTRAINT member_fund_journal_balanced CHECK (amount_minor > 0 AND debit_account_id <> credit_account_id)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_member_fund_journal_rewrite() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Posted member fund journal records cannot be rewritten' USING ERRCODE = '23514';
END;
$$;

CREATE TRIGGER member_fund_journals_append_only BEFORE UPDATE OR DELETE ON member_fund_journals
FOR EACH ROW EXECUTE FUNCTION reject_member_fund_journal_rewrite();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('member_fund_journals');
        DB::statement('DROP FUNCTION reject_member_fund_journal_rewrite()');
    }
};
