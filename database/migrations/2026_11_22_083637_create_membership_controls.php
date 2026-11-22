<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_eligibility_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('min_savings_minor');
            $table->unsignedBigInteger('min_shares_minor');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('changed_at');
        });

        Schema::create('member_verified_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->date('as_of');
            $table->unsignedBigInteger('savings_minor');
            $table->unsignedBigInteger('shares_minor');
            $table->string('evidence_reference', 500);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->index(['member_id', 'as_of', 'id']);
        });

        Schema::table('loan_applications', function (Blueprint $table): void {
            $table->foreignId('loan_eligibility_revision_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('member_verified_balance_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::create('member_exits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('status', 16);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->string('reason', 500);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('finalized_at')->nullable();
            $table->date('effective_on')->nullable();
            $table->string('settlement_reference', 500)->nullable();
            $table->string('legacy_clearance_reference', 500)->nullable();
        });

        DB::statement("CREATE UNIQUE INDEX member_exits_open_unique ON member_exits (member_id) WHERE status IN ('requested', 'approved')");
    }

    public function down(): void
    {
        Schema::dropIfExists('member_exits');
        Schema::table('loan_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('loan_eligibility_revision_id');
            $table->dropConstrainedForeignId('member_verified_balance_id');
        });
        Schema::dropIfExists('member_verified_balances');
        Schema::dropIfExists('loan_eligibility_revisions');
    }
};
