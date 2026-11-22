<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('general_journals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('preview_token')->unique();
            $table->date('transaction_date')->index();
            $table->string('category', 20);
            $table->string('source_reference', 100);
            $table->string('description', 500);
            $table->smallInteger('line_count');
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at');
        });
        Schema::create('general_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('general_journal_id')->constrained()->restrictOnDelete();
            $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('signed_minor');
            $table->unique(['general_journal_id', 'chart_account_id']);
            $table->index('chart_account_id');
        });
        Schema::table('ledger_journal_reversals', function (Blueprint $table): void {
            $table->foreignId('general_journal_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE ledger_journal_reversals DROP CONSTRAINT ledger_reversal_single_source;
ALTER TABLE ledger_journal_reversals ADD CONSTRAINT ledger_reversal_single_source
    CHECK (num_nonnulls(loan_journal_posting_id, member_fund_journal_id, general_journal_id) = 1);
ALTER TABLE general_journals ADD CHECK (line_count BETWEEN 2 AND 20 AND category IN ('expense', 'fee', 'general'));
ALTER TABLE general_journal_lines ADD CHECK (signed_minor BETWEEN -99999999999999 AND 99999999999999 AND signed_minor <> 0);

CREATE OR REPLACE FUNCTION verify_general_journal_balance() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE journal_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'general_journals' THEN
        journal_id := NEW.id;
    ELSE
        journal_id := NEW.general_journal_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM general_journals AS journal
        JOIN general_journal_lines AS line ON line.general_journal_id = journal.id
        WHERE journal.id = journal_id
        GROUP BY journal.id, journal.line_count
        HAVING count(*) = journal.line_count AND sum(line.signed_minor) = 0
    ) THEN
        RAISE EXCEPTION 'Invalid general journal %', journal_id USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER general_journal_balanced AFTER INSERT ON general_journals
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_general_journal_balance();
CREATE CONSTRAINT TRIGGER general_journal_lines_balanced AFTER INSERT ON general_journal_lines
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_general_journal_balance();

CREATE OR REPLACE FUNCTION reject_general_journal_rewrite() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Posted general journals cannot be rewritten' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER general_journal_append_only BEFORE UPDATE OR DELETE ON general_journals
FOR EACH ROW EXECUTE FUNCTION reject_general_journal_rewrite();
CREATE TRIGGER general_journal_lines_append_only BEFORE UPDATE OR DELETE ON general_journal_lines
FOR EACH ROW EXECUTE FUNCTION reject_general_journal_rewrite();
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_journal_reversals DROP CONSTRAINT ledger_reversal_single_source');
        DB::statement('ALTER TABLE ledger_journal_reversals ADD CONSTRAINT ledger_reversal_single_source CHECK (num_nonnulls(loan_journal_posting_id, member_fund_journal_id) = 1)');
        Schema::table('ledger_journal_reversals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('general_journal_id');
        });
        Schema::dropIfExists('general_journal_lines');
        Schema::dropIfExists('general_journals');
        DB::statement('DROP FUNCTION verify_general_journal_balance()');
        DB::statement('DROP FUNCTION reject_general_journal_rewrite()');
    }
};
