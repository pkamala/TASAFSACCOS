<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_member_id')->nullable()->unique();
            $table->string('legacy_registration_no', 30)->nullable();
            $table->char('legacy_source_sha256', 64)->nullable();
            $table->string('middle_name', 100)->nullable();
            $table->string('phone', 16)->nullable()->change();
            $table->date('joining_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->string('phone', 16)->nullable(false)->change();
            $table->date('joining_date')->nullable(false)->change();
            $table->dropUnique(['legacy_member_id']);
            $table->dropColumn(['legacy_member_id', 'legacy_registration_no', 'legacy_source_sha256', 'middle_name']);
        });
    }
};
