<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_book_accounts', function (Blueprint $table): void {
            $table->foreignId('chart_account_id')->primary()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_book_accounts');
    }
};
