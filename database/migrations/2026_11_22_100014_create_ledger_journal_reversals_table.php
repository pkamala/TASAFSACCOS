<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_journal_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_journal_posting_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('member_fund_journal_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->date('transaction_date');
            $table->string('reason', 500);
            $table->foreignId('reversed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('reversed_at');
            $table->index('transaction_date');
        });

        Schema::create('ledger_journal_reversal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ledger_journal_reversal_id')->constrained()->restrictOnDelete();
            $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('subject_id');
            $table->string('source_reference', 100);
            $table->bigInteger('signed_minor');
            $table->index('chart_account_id');
        });

        DB::statement('ALTER TABLE ledger_journal_reversals ADD CONSTRAINT ledger_reversal_single_source CHECK (num_nonnulls(loan_journal_posting_id, member_fund_journal_id) = 1)');
        DB::statement('ALTER TABLE ledger_journal_reversal_lines ADD CONSTRAINT ledger_reversal_nonzero CHECK (signed_minor <> 0)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION verify_ledger_reversal_balance() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE reversal_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'ledger_journal_reversals' THEN
        reversal_id := NEW.id;
    ELSE
        reversal_id := NEW.ledger_journal_reversal_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM ledger_journal_reversal_lines WHERE ledger_journal_reversal_id = reversal_id
        GROUP BY ledger_journal_reversal_id HAVING count(*) >= 2 AND sum(signed_minor) = 0
    ) THEN
        RAISE EXCEPTION 'Unbalanced journal reversal %', reversal_id USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER ledger_reversal_balanced AFTER INSERT ON ledger_journal_reversals
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_ledger_reversal_balance();
CREATE CONSTRAINT TRIGGER ledger_reversal_lines_balanced AFTER INSERT ON ledger_journal_reversal_lines
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_ledger_reversal_balance();

CREATE OR REPLACE FUNCTION reject_ledger_reversal_rewrite() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Posted journal reversals cannot be rewritten' USING ERRCODE = '23514';
END;
$$;

CREATE TRIGGER ledger_reversal_append_only BEFORE UPDATE OR DELETE ON ledger_journal_reversals
FOR EACH ROW EXECUTE FUNCTION reject_ledger_reversal_rewrite();
CREATE TRIGGER ledger_reversal_lines_append_only BEFORE UPDATE OR DELETE ON ledger_journal_reversal_lines
FOR EACH ROW EXECUTE FUNCTION reject_ledger_reversal_rewrite();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_journal_reversal_lines');
        Schema::dropIfExists('ledger_journal_reversals');
        DB::statement('DROP FUNCTION verify_ledger_reversal_balance()');
        DB::statement('DROP FUNCTION reject_ledger_reversal_rewrite()');
    }
};
