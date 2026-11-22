<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table): void {
            $table->foreignId('loan_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('transferred_on');
        });

        Schema::table('loan_repayment_receipts', function (Blueprint $table): void {
            $table->foreignId('loan_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('received_on');
        });
    }

    public function down(): void
    {
        Schema::table('loan_repayment_receipts', function (Blueprint $table): void {
            $table->dropIndex(['received_on']);
            $table->dropConstrainedForeignId('loan_batch_id');
        });

        Schema::table('loan_disbursements', function (Blueprint $table): void {
            $table->dropIndex(['transferred_on']);
            $table->dropConstrainedForeignId('loan_batch_id');
        });
    }
};
