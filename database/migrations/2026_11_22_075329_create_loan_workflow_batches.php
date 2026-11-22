<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('board_role', 16)->nullable());
        Schema::create('loan_evidence', function (Blueprint $table): void {
            $table->id();
            $table->text('pdf_base64');
            $table->char('sha256', 64);
        });
        Schema::table('loan_application_decisions', function (Blueprint $table): void {
            $table->foreignId('minutes_id')->nullable()->constrained('loan_evidence')->restrictOnDelete();
        });
        Schema::create('loan_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->date('period')->nullable();
            $table->jsonb('items');
            $table->string('status', 16);
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('prepared_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->date('effective_date')->nullable();
            $table->foreignId('evidence_id')->nullable()->constrained('loan_evidence')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_batches');
        Schema::table('loan_application_decisions', fn (Blueprint $table) => $table->dropConstrainedForeignId('minutes_id'));
        Schema::dropIfExists('loan_evidence');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('board_role'));
    }
};
