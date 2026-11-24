<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_register', function (Blueprint $table): void {
            $table->id();
            $table->string('item', 160);
            $table->unsignedBigInteger('value_minor');
            $table->unsignedBigInteger('depreciation_minor');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        Schema::create('annual_budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['year', 'chart_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annual_budget_lines');
        Schema::dropIfExists('asset_register');
    }
};
