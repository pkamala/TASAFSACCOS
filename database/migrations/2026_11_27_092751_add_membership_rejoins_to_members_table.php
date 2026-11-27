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
            $table->foreignId('previous_membership_id')->nullable()->unique()->constrained('members')->restrictOnDelete();
            $table->dropUnique(['email']);
        });

        DB::statement("CREATE UNIQUE INDEX members_current_email_unique ON members (email) WHERE status <> 'exited'");
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->unique('email');
            $table->dropConstrainedForeignId('previous_membership_id');
        });

        DB::statement('DROP INDEX members_current_email_unique');
    }
};
