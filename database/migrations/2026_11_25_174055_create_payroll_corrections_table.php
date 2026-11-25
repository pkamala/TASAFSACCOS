<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_corrections', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 100)->unique();
            $table->date('payroll_month');
            $table->string('entry', 160);
            $table->string('source_file')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('issue', 500);
            $table->string('resolution', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_corrections');
    }
};
