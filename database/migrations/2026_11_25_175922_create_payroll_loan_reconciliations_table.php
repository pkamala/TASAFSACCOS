<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_loan_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->date('payroll_month');
            $table->string('source_file');
            $table->char('source_sha256', 64);
            $table->unsignedInteger('source_row');
            $table->unsignedSmallInteger('loan_slot');
            $table->string('member_name', 160);
            $table->string('source_member_number', 30)->nullable();
            $table->string('source_loan_reference', 100)->nullable();
            $table->foreignId('member_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('total_minor');
            $table->bigInteger('principal_minor')->nullable();
            $table->bigInteger('interest_minor')->nullable();
            $table->string('source_issue', 255)->nullable();
            $table->foreignId('receipt_id')->nullable()->unique()->constrained('loan_repayment_receipts')->restrictOnDelete();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reconciled_at')->nullable();
            $table->unique(['source_sha256', 'source_row', 'loan_slot'], 'payroll_loan_source_unique');
            $table->index(['payroll_month', 'receipt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_loan_reconciliations');
    }
};
