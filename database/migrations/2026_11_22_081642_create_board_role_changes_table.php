<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->unique('board_role'));

        Schema::create('board_role_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 16);
            $table->foreignId('previous_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('next_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_role_changes');
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique(['board_role']));
    }
};
