<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_exit_cancellations', function (Blueprint $table): void {
            $table->foreignId('loan_application_id')->constrained()->restrictOnDelete()->primary();
            $table->foreignId('member_exit_id')->constrained()->restrictOnDelete();
            $table->foreignId('cancelled_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_exit_cancellations');
    }
};
