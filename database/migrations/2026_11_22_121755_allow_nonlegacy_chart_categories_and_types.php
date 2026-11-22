<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['account_categories', 'account_types'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('legacy_id')->nullable()->change();
                $table->char('legacy_source_sha256', 64)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['account_types', 'account_categories'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('legacy_id')->nullable(false)->change();
                $table->char('legacy_source_sha256', 64)->nullable(false)->change();
            });
        }
    }
};
