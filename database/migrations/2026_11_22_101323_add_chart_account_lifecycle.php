<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_id')->nullable()->change();
            $table->char('legacy_source_sha256', 64)->nullable()->change();
            $table->foreignId('replaces_account_id')->nullable()->unique()->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->nullable();
            $table->string('creation_reason', 500)->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->string('retirement_reason', 500)->nullable();
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION preserve_chart_account_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Chart accounts must be retired through the account workflow' USING ERRCODE = '23514';
    END IF;

    IF (to_jsonb(NEW) - 'retired_at' - 'retired_by' - 'retirement_reason')
        IS DISTINCT FROM (to_jsonb(OLD) - 'retired_at' - 'retired_by' - 'retirement_reason') OR OLD.retired_at IS NOT NULL
    THEN
        RAISE EXCEPTION 'Account details are historical; create a replacement account' USING ERRCODE = '23514';
    END IF;
    IF num_nonnulls(NEW.retired_at, NEW.retired_by, NEW.retirement_reason) <> 3 THEN
        RAISE EXCEPTION 'Account retirement requires an actor, timestamp and reason' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER chart_accounts_preserve_identity BEFORE UPDATE OR DELETE ON chart_accounts
FOR EACH ROW EXECUTE FUNCTION preserve_chart_account_identity();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER chart_accounts_preserve_identity ON chart_accounts');
        DB::statement('DROP FUNCTION preserve_chart_account_identity()');
        Schema::table('chart_accounts', function (Blueprint $table): void {
            $table->dropUnique(['replaces_account_id']);
            $table->dropConstrainedForeignId('replaces_account_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('retired_by');
            $table->dropColumn(['created_at', 'creation_reason', 'retired_at', 'retirement_reason']);
            $table->unsignedBigInteger('legacy_id')->nullable(false)->change();
            $table->char('legacy_source_sha256', 64)->nullable(false)->change();
        });
    }
};
