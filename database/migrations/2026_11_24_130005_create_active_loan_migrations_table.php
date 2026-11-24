<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('active_loan_migrations', function (Blueprint $table): void {
            $table->foreignId('loan_application_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('verified_balance_id')->unique()->constrained('verified_legacy_loan_balances')->restrictOnDelete();
            $table->char('source_sha256', 64);
            $table->unsignedBigInteger('source_id');
            $table->unique(['source_sha256', 'source_id']);
            $table->date('effective_on');
            $table->char('schedule_sha256', 64);
            $table->string('review_reference', 500);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_loan_migrations');
    }
};
