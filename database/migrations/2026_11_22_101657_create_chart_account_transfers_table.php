<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_account_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('original_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('replacement_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->bigInteger('signed_minor');
            $table->date('transaction_date');
            $table->string('reason', 500);
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at');
            $table->index(['original_account_id', 'transaction_date']);
            $table->index(['replacement_account_id', 'transaction_date']);
        });
        DB::statement('ALTER TABLE chart_account_transfers ADD CHECK (original_account_id <> replacement_account_id AND signed_minor <> 0)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_chart_account_transfer_rewrite() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Posted chart account transfers cannot be rewritten' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER chart_account_transfer_append_only BEFORE UPDATE OR DELETE ON chart_account_transfers
FOR EACH ROW EXECUTE FUNCTION reject_chart_account_transfer_rewrite();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_account_transfers');
        DB::statement('DROP FUNCTION reject_chart_account_transfer_rewrite()');
    }
};
