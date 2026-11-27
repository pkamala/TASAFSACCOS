<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_book_review_opening_lines', function (Blueprint $table): void {
            $table->char('source_sha256', 64);
            $table->string('source_file');
            $table->date('as_of');
            $table->unsignedInteger('source_row');
            $table->string('description');
            $table->string('account_code')->nullable();
            $table->bigInteger('signed_minor');
            $table->primary(['source_sha256', 'source_row']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_book_review_opening_lines');
    }
};
