<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_opening_journals', function (Blueprint $table): void {
            $table->id();
            $table->boolean('singleton')->default(true)->unique();
            $table->date('effective_on');
            $table->string('review_reference', 500);
            $table->char('source_sha256', 64);
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at');
        });
        Schema::create('ledger_opening_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ledger_opening_journal_id')->constrained()->restrictOnDelete();
            $table->foreignId('chart_account_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('signed_minor');
        });

        DB::statement('ALTER TABLE ledger_opening_journals ADD CHECK (singleton)');
        DB::statement('ALTER TABLE ledger_opening_lines ADD CHECK (signed_minor <> 0)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION verify_ledger_opening_balance() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE opening_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'ledger_opening_journals' THEN
        opening_id := NEW.id;
    ELSE
        opening_id := NEW.ledger_opening_journal_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM ledger_opening_lines WHERE ledger_opening_journal_id = opening_id
        GROUP BY ledger_opening_journal_id HAVING count(*) >= 2 AND sum(signed_minor) = 0
    ) THEN
        RAISE EXCEPTION 'Unbalanced ledger opening journal %', opening_id USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER ledger_opening_balanced AFTER INSERT ON ledger_opening_journals
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_ledger_opening_balance();
CREATE CONSTRAINT TRIGGER ledger_opening_lines_balanced AFTER INSERT ON ledger_opening_lines
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_ledger_opening_balance();

CREATE OR REPLACE FUNCTION reject_ledger_opening_rewrite() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Posted opening journals cannot be rewritten' USING ERRCODE = '23514';
END;
$$;

CREATE TRIGGER ledger_opening_append_only BEFORE UPDATE OR DELETE ON ledger_opening_journals
FOR EACH ROW EXECUTE FUNCTION reject_ledger_opening_rewrite();
CREATE TRIGGER ledger_opening_lines_append_only BEFORE UPDATE OR DELETE ON ledger_opening_lines
FOR EACH ROW EXECUTE FUNCTION reject_ledger_opening_rewrite();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_opening_lines');
        Schema::dropIfExists('ledger_opening_journals');
        DB::statement('DROP FUNCTION verify_ledger_opening_balance()');
        DB::statement('DROP FUNCTION reject_ledger_opening_rewrite()');
    }
};
