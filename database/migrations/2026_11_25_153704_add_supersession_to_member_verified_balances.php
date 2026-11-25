<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_verified_balances', function (Blueprint $table): void {
            $table->foreignId('superseded_by_id')->nullable()->constrained('member_verified_balances')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('member_verified_balances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('superseded_by_id');
        });
    }
};
