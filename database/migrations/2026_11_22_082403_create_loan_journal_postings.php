<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_journal_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_batch_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at');
        });

        Schema::create('loan_journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_journal_posting_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_application_id')->constrained()->restrictOnDelete();
            $table->date('transaction_date');
            $table->string('source_reference', 100);
            $table->unique(['loan_journal_posting_id', 'loan_application_id'], 'loan_journal_posting_application_unique');
        });

        Schema::create('loan_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('signed_minor');
            $table->unique(['loan_journal_entry_id', 'chart_account_id'], 'loan_journal_entry_account_unique');
        });

        DB::statement('ALTER TABLE loan_journal_lines ADD CONSTRAINT loan_journal_nonzero CHECK (signed_minor <> 0)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION verify_loan_journal_balance() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE entry_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'loan_journal_entries' THEN
        entry_id := NEW.id;
    ELSE
        entry_id := NEW.loan_journal_entry_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM loan_journal_lines WHERE loan_journal_entry_id = entry_id
        GROUP BY loan_journal_entry_id HAVING count(*) >= 2 AND sum(signed_minor) = 0
    ) THEN
        RAISE EXCEPTION 'Unbalanced loan journal entry %', entry_id USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER loan_journal_entry_balanced AFTER INSERT ON loan_journal_entries
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_loan_journal_balance();

CREATE CONSTRAINT TRIGGER loan_journal_lines_balanced AFTER INSERT ON loan_journal_lines
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_loan_journal_balance();

CREATE OR REPLACE FUNCTION reject_loan_journal_rewrite() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Posted loan journal records cannot be rewritten' USING ERRCODE = '23514';
END;
$$;

CREATE TRIGGER loan_journal_postings_append_only BEFORE UPDATE OR DELETE ON loan_journal_postings
FOR EACH ROW EXECUTE FUNCTION reject_loan_journal_rewrite();
CREATE TRIGGER loan_journal_entries_append_only BEFORE UPDATE OR DELETE ON loan_journal_entries
FOR EACH ROW EXECUTE FUNCTION reject_loan_journal_rewrite();
CREATE TRIGGER loan_journal_lines_append_only BEFORE UPDATE OR DELETE ON loan_journal_lines
FOR EACH ROW EXECUTE FUNCTION reject_loan_journal_rewrite();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_journal_lines');
        Schema::dropIfExists('loan_journal_entries');
        Schema::dropIfExists('loan_journal_postings');
        DB::statement('DROP FUNCTION verify_loan_journal_balance()');
        DB::statement('DROP FUNCTION reject_loan_journal_rewrite()');
    }
};
