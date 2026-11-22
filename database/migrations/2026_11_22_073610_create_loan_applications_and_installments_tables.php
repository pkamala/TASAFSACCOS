<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_applications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('submission_id')->unique();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('principal_minor');
            $table->unsignedBigInteger('insurance_minor');
            $table->unsignedBigInteger('processing_fee_minor');
            $table->unsignedBigInteger('other_charges_minor');
            $table->unsignedSmallInteger('annual_basis_points');
            $table->unsignedSmallInteger('term_months');
            $table->string('interest_method', 16);
            $table->date('first_due_date');
            $table->string('status', 16);
            $table->timestampTz('created_at');
            $table->index(['member_id', 'id']);
        });

        Schema::create('loan_application_installments', function (Blueprint $table): void {
            $table->foreignId('loan_application_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_date');
            foreach (['opening', 'principal', 'interest', 'payment', 'closing'] as $amount) {
                $table->unsignedBigInteger($amount.'_minor');
            }
            $table->primary(['loan_application_id', 'number'], 'loan_application_installment_pk');
        });

        Schema::create('loan_application_decisions', function (Blueprint $table): void {
            $table->foreignId('loan_application_id')->constrained()->restrictOnDelete()->primary();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('reason', 500);
            $table->timestampTz('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_application_decisions');
        Schema::dropIfExists('loan_application_installments');
        Schema::dropIfExists('loan_applications');
    }
};
