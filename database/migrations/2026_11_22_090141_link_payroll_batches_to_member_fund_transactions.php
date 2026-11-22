<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_fund_transactions', function (Blueprint $table): void {
            $table->foreignId('loan_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('loan_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('member_fund_transactions', function (Blueprint $table): void {
            $table->dropIndex(['loan_batch_id']);
            $table->dropConstrainedForeignId('loan_batch_id');
        });
    }
};
