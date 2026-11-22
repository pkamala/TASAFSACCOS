<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX legacy_loans_member_idx ON legacy_onboarding_rows (source_sha256, (data->>'member_id')) WHERE source_table = 'loans'");
        DB::statement("CREATE INDEX legacy_schedules_loan_idx ON legacy_onboarding_rows (source_sha256, (data->>'loan_id')) WHERE source_table = 'loanschedules'");
        DB::statement("CREATE INDEX legacy_repayments_loan_idx ON legacy_onboarding_rows (source_sha256, (data->>'resolved_loan_id')) WHERE source_table = 'repayments'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX legacy_repayments_loan_idx, legacy_schedules_loan_idx, legacy_loans_member_idx');
    }
};
