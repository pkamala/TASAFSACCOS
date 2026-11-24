<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_sponsorships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_application_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->unique(['loan_application_id', 'member_id']);
            $table->index(['member_id', 'loan_application_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_sponsorships');
    }
};
