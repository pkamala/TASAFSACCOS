<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_pricing_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('annual_basis_points');
            $table->string('interest_method', 16);
            $table->unsignedSmallInteger('insurance_basis_points');
            $table->unsignedBigInteger('processing_fee_minor');
            $table->unsignedBigInteger('other_charges_minor');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('changed_at');
        });

        Schema::table('loan_applications', function (Blueprint $table): void {
            $table->foreignId('loan_pricing_revision_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('loan_pricing_revision_id');
        });
        Schema::dropIfExists('loan_pricing_revisions');
    }
};
