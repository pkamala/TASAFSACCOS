<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_exits', function (Blueprint $table): void {
            $table->jsonb('voucher_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('member_exits', function (Blueprint $table): void {
            $table->dropColumn('voucher_snapshot');
        });
    }
};
