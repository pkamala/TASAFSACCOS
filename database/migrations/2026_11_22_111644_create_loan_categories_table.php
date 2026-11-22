<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
        });
        DB::statement('CREATE UNIQUE INDEX loan_categories_name_unique ON loan_categories (lower(name))');
        DB::table('loan_categories')->insert([
            ['name' => 'Staff loan', 'created_at' => now()],
            ['name' => 'Emergency loan', 'created_at' => now()],
        ]);

        Schema::table('loan_applications', function (Blueprint $table): void {
            $table->foreignId('loan_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('loan_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('loan_category_id');
        });
        Schema::dropIfExists('loan_categories');
    }
};
