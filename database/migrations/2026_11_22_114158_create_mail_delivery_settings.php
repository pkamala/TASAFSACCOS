<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_mail_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('protocol', 8);
            $table->string('host');
            $table->integer('port');
            $table->string('security', 12);
            $table->string('username');
            $table->text('encrypted_password');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');
        });

        Schema::create('statement_auto_send_changes', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_auto_send_changes');
        Schema::dropIfExists('incoming_mail_settings');
    }
};
