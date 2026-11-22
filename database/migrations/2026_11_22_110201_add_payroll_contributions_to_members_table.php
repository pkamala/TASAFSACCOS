<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->bigInteger('payroll_savings_minor')->default(0);
            $table->bigInteger('payroll_shares_minor')->default(0);
        });
        DB::statement('ALTER TABLE members ADD CONSTRAINT members_payroll_nonnegative CHECK (payroll_savings_minor >= 0 AND payroll_shares_minor >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE members DROP CONSTRAINT members_payroll_nonnegative');
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn(['payroll_savings_minor', 'payroll_shares_minor']));
    }
};
