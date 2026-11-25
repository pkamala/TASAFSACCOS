<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agm_meetings', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 160);
            $table->string('venue', 255);
            $table->timestampTz('starts_at');
            $table->timestampTz('registration_closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
        });

        Schema::create('agm_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agm_meeting_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('registration_no', 30);
            $table->string('member_name', 305);
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->timestampTz('registered_at');
            $table->timestampTz('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['agm_meeting_id', 'member_id']);
            $table->index(['member_id', 'agm_meeting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agm_registrations');
        Schema::dropIfExists('agm_meetings');
    }
};
