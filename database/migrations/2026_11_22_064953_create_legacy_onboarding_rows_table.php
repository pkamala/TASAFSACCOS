<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_onboarding_rows', function (Blueprint $table): void {
            $table->char('source_sha256', 64);
            $table->string('source_table', 32);
            $table->unsignedBigInteger('source_id');
            $table->jsonb('data');
            $table->jsonb('issues');
            $table->primary(['source_sha256', 'source_table', 'source_id'], 'legacy_onboarding_rows_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_onboarding_rows');
    }
};
