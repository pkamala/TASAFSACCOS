<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exit_settlement_rate_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('savings_interest_basis_points');
            $table->unsignedSmallInteger('withholding_basis_points');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_settlement_rate_revisions');
    }
};
