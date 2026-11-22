<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_disbursements', function (Blueprint $table): void {
            $table->foreignId('loan_application_id')->constrained()->restrictOnDelete()->primary();
            $table->string('transfer_reference', 100)->unique();
            $table->unsignedBigInteger('cash_amount_minor');
            $table->date('transferred_on');
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_disbursements');
    }
};
