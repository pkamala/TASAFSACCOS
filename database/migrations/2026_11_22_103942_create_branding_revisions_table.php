<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_revisions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('mark', 3);
            $table->string('theme', 12);
            $table->string('logo_type', 20)->nullable();
            $table->text('logo_base64')->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_branding_rewrite() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Branding revisions cannot be rewritten' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER branding_revisions_append_only BEFORE UPDATE OR DELETE ON branding_revisions
FOR EACH ROW EXECUTE FUNCTION reject_branding_rewrite();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_revisions');
        DB::statement('DROP FUNCTION reject_branding_rewrite()');
    }
};
