<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('member_savings_withdrawals', function (Blueprint $table) {
            $table->unsignedBigInteger('savings_at_request_minor')->nullable();
            $table->date('last_paid_withdrawal_on')->nullable();
            $table->string('board_decision_reference', 100)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('member_savings_withdrawals', function (Blueprint $table) {
            $table->dropColumn(['savings_at_request_minor', 'last_paid_withdrawal_on', 'board_decision_reference']);
        });
    }
};
