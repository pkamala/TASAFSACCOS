<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_period_events', function (Blueprint $table): void {
            $table->id();
            $table->date('period');
            $table->string('action', 10);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('recorded_at');
            $table->index(['period', 'id']);
        });
        DB::statement("ALTER TABLE ledger_period_events ADD CHECK (extract(day FROM period) = 1 AND action IN ('closed', 'reopened'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_period_events');
    }
};
