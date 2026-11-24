<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('loan_workflow_role', 20)->nullable();
        });
        Schema::table('loan_applications', function (Blueprint $table): void {
            $table->string('approval_lane', 16)->nullable();
            $table->foreignId('recommended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('recommended_at')->nullable();
            $table->string('recommendation', 20)->nullable();
            $table->string('recommendation_note', 1000)->nullable();
        });
        DB::table('loan_applications')->where('status', 'submitted')->update(['approval_lane' => 'pending']);
        DB::table('members')->where('status', 'inactive')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('member_exits as exit')
                ->whereColumn('exit.member_id', 'members.id')->where('exit.status', 'finalized'))->update(['status' => 'exited']);
    }

    public function down(): void
    {
        DB::table('members')->where('status', 'exited')->update(['status' => 'inactive']);
        Schema::table('loan_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recommended_by');
            $table->dropColumn(['approval_lane', 'recommended_at', 'recommendation', 'recommendation_note']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('loan_workflow_role');
        });
    }
};
