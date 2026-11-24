<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_batches', function (Blueprint $table): void {
            $table->jsonb('deduction_review')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('loan_batches', function (Blueprint $table): void {
            $table->dropColumn('deduction_review');
        });
    }
};
