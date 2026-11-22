<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->char('legacy_source_sha256', 64);
            $table->string('name');
            $table->string('code')->nullable();
        });

        Schema::create('account_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->char('legacy_source_sha256', 64);
            $table->foreignId('account_category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('reference_name')->nullable();
        });

        Schema::create('chart_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->char('legacy_source_sha256', 64);
            $table->foreignId('account_type_id')->constrained()->restrictOnDelete();
            $table->string('code')->index();
            $table->string('name');
            $table->string('name_sw')->nullable();
            $table->string('name_en')->nullable();
            $table->boolean('is_ledger_account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_accounts');
        Schema::dropIfExists('account_types');
        Schema::dropIfExists('account_categories');
    }
};
