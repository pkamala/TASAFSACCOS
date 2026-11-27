<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_book_monthly_balances', function (Blueprint $table): void {
            $table->date('month');
            $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('opening_minor');
            $table->unsignedBigInteger('receipts_minor');
            $table->unsignedBigInteger('payments_minor');
            $table->bigInteger('closing_minor');
            $table->string('source_file');
            $table->char('source_sha256', 64);
            $table->primary(['month', 'chart_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_book_monthly_balances');
    }
};
