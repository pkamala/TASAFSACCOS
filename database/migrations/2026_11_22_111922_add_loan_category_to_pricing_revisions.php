<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_pricing_revisions', function (Blueprint $table): void {
            $table->foreignId('loan_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['loan_category_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('loan_pricing_revisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('loan_category_id');
        });
    }
};
