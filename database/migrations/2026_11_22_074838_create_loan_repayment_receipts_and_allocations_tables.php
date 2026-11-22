<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_repayment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_application_id')->constrained()->restrictOnDelete();
            $table->string('receipt_reference', 100);
            $table->date('received_on');
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->unique(['loan_application_id', 'receipt_reference'], 'loan_receipt_reference_unique');
            $table->unique(['loan_application_id', 'id'], 'loan_receipt_application_unique');
        });

        Schema::create('loan_repayment_allocations', function (Blueprint $table): void {
            $table->unsignedBigInteger('loan_application_id');
            $table->unsignedBigInteger('loan_repayment_receipt_id');
            $table->unsignedSmallInteger('installment_number');
            $table->unsignedBigInteger('principal_minor');
            $table->unsignedBigInteger('interest_minor');
            $table->primary(['loan_repayment_receipt_id', 'installment_number'], 'loan_repayment_allocation_pk');
            $table->foreign(['loan_application_id', 'loan_repayment_receipt_id'], 'loan_allocation_receipt_fk')
                ->references(['loan_application_id', 'id'])->on('loan_repayment_receipts')->restrictOnDelete();
            $table->foreign(['loan_application_id', 'installment_number'], 'loan_allocation_installment_fk')
                ->references(['loan_application_id', 'number'])->on('loan_application_installments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayment_allocations');
        Schema::dropIfExists('loan_repayment_receipts');
    }
};
