<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_loan_corrections', function (Blueprint $table): void {
            $table->char('source_sha256', 64);
            $table->string('source_table', 32);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('loan_id');
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->string('evidence_reference', 500);
            $table->string('reviewed_by');
            $table->date('reviewed_on');
            $table->char('csv_sha256', 64);
            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('applied_at');
            $table->primary(['source_sha256', 'source_table', 'source_id'], 'legacy_corrections_pk');
            $table->foreign(['source_sha256', 'source_table', 'source_id'], 'legacy_corrections_source_fk')
                ->references(['source_sha256', 'source_table', 'source_id'])->on('legacy_onboarding_rows')->restrictOnDelete();
            $table->index(['source_sha256', 'source_table', 'loan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_loan_corrections');
    }
};
