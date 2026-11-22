<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailing_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 80);
            $table->string('from_address');
            $table->string('from_name', 100);
            $table->string('host');
            $table->integer('port');
            $table->string('security', 12);
            $table->string('username');
            $table->text('encrypted_password');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
        });

        Schema::create('mailing_account_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailing_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailing_account_selections');
        Schema::dropIfExists('mailing_accounts');
    }
};
