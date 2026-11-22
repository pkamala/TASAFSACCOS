<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_member_statements', function (Blueprint $table): void {
            $table->foreignId('queued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('review_reference', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monthly_member_statements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('queued_by');
            $table->dropColumn('review_reference');
        });
    }
};
