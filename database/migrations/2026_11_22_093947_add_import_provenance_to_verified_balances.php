<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_verified_balances', fn (Blueprint $table) => $table->char('import_sha256', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('member_verified_balances', fn (Blueprint $table) => $table->dropColumn('import_sha256'));
    }
};
