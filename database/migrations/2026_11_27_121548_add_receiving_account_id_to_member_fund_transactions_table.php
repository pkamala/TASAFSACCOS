<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_fund_transactions', function (Blueprint $table): void {
            $table->foreignId('receiving_account_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('member_fund_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('receiving_account_id');
        });
    }
};
