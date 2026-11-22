<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_member_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->date('period');
            $table->string('recipient');
            $table->unsignedBigInteger('loan_balance_minor');
            $table->unsignedBigInteger('contribution_minor');
            $table->unsignedBigInteger('savings_balance_minor');
            $table->char('source_sha256', 64);
            $table->string('delivery_status', 20)->default('pending');
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['period', 'member_id']);
            $table->index(['period', 'delivery_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_member_statements');
    }
};
