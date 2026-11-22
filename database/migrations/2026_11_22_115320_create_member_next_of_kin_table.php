<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_next_of_kin', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('full_name', 200);
            $table->string('relationship', 80);
            $table->string('phone', 16);
            $table->string('email')->nullable();
            $table->timestampsTz();
            $table->index(['member_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_next_of_kin');
    }
};
