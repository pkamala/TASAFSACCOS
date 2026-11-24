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
            $table->jsonb('loan_workflow_roles')->default('[]');
        });
        DB::table('users')->whereNotNull('loan_workflow_role')
            ->update(['loan_workflow_roles' => DB::raw('jsonb_build_array(loan_workflow_role)')]);
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('loan_workflow_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('loan_workflow_role', 20)->nullable();
        });
        DB::table('users')->update(['loan_workflow_role' => DB::raw(<<<'SQL'
            CASE
                WHEN loan_workflow_roles @> '["board"]' THEN 'board'
                WHEN loan_workflow_roles @> '["committee"]' THEN 'committee'
                WHEN loan_workflow_roles @> '["staff"]' THEN 'staff'
            END
            SQL)]);
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('loan_workflow_roles');
        });
    }
};
