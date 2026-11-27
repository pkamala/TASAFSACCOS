<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_book_review_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 100)->unique();
            $table->string('source_file');
            $table->char('source_sha256', 64);
            $table->date('month');
            $table->unsignedInteger('source_row');
            $table->string('raw_date')->nullable();
            $table->date('transaction_date')->nullable();
            $table->string('payee')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('workflow', 50);
            $table->text('issues')->nullable();
            $table->jsonb('source_lines');
            $table->jsonb('match_candidates');
            $table->string('status', 16)->default('pending');
            $table->string('matched_journal', 100)->nullable()->unique();
            $table->jsonb('approved_lines')->nullable();
            $table->date('approved_date')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('general_journal_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->index(['status', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_book_review_entries');
    }
};
