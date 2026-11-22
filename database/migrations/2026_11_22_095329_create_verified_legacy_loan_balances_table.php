<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verified_legacy_loan_balances', function (Blueprint $table): void {
            $table->id();
            $table->char('source_sha256', 64);
            $table->string('source_table', 32)->default('loans');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->date('as_of');
            $table->unsignedBigInteger('principal_minor');
            $table->string('evidence_reference', 500);
            $table->char('report_sha256', 64);
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at');
            $table->unique(['source_sha256', 'as_of', 'source_id'], 'verified_legacy_loan_period_unique');
            $table->foreign(['source_sha256', 'source_table', 'source_id'], 'verified_legacy_loan_source_fk')
                ->references(['source_sha256', 'source_table', 'source_id'])->on('legacy_onboarding_rows')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE verified_legacy_loan_balances ADD CHECK (source_table = 'loans' AND principal_minor >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('verified_legacy_loan_balances');
    }
};
