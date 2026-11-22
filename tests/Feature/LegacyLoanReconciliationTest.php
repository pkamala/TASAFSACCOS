<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyLoanReconciliationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_can_compare_a_dated_report_using_corrected_principal_allocations(): void
    {
        $snapshot = str_repeat('a', 64);
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $rows = [
            ['loans', 7, ['loan_code' => 'LN-7', 'principle' => '100000', 'disbursement_date' => '2025-01-01'], []],
            ['loans', 8, ['loan_code' => 'LN-8', 'principle' => '400', 'disbursement_date' => '2025-01-01'], []],
            ['repayments', 1, ['paymentdate' => '2025-02-28', 'principlepayed' => '20000.25', 'resolved_loan_id' => '7'], []],
            ['repayments', 2, ['paymentdate' => '2025-03-10', 'principlepayed' => '5000', 'resolved_loan_id' => '7'], []],
            ['repayments', 3, ['paymentdate' => '2025-02-10', 'principlepayed' => '10000', 'resolved_loan_id' => null], ['conflict_loan_link']],
            ['repayments', 4, ['paymentdate' => '0000-00-00', 'principlepayed' => '50', 'resolved_loan_id' => '8'], []],
            ['repayments', 5, ['paymentdate' => null, 'principlepayed' => '500', 'resolved_loan_id' => null], ['unresolved_loan_link']],
        ];

        foreach ($rows as [$table, $id, $data, $issues]) {
            DB::table('legacy_onboarding_rows')->insert([
                'source_sha256' => $snapshot,
                'source_table' => $table,
                'source_id' => $id,
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                'issues' => json_encode($issues, JSON_THROW_ON_ERROR),
            ]);
        }

        DB::table('legacy_loan_corrections')->insert([
            'source_sha256' => $snapshot, 'source_table' => 'repayments', 'source_id' => 3,
            'loan_id' => 7, 'schedule_id' => 800, 'evidence_reference' => 'Receipt',
            'reviewed_by' => 'Finance', 'reviewed_on' => '2025-03-01',
            'csv_sha256' => str_repeat('b', 64), 'applied_by' => $admin->id, 'applied_at' => now(),
        ]);
        $path = '/staff/loan-reconciliation';
        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get($path)->assertForbidden();

        $this->actingAs($admin)->get("$path/template")->assertOk()->assertDownload('legacy_balance_report.csv');
        $header = "legacy_loan_id,principal_balance_tzs\n";
        $inputs = ['snapshot' => $snapshot, 'as_of' => '2025-02-28'];
        $this->from($path)->post($path, [
            ...$inputs,
            'report' => UploadedFile::fake()->createWithContent('report.csv', $header."7,70000\n7,70000"),
        ])->assertSessionHasErrors('report');

        $this->post($path, [
            ...$inputs,
            'report' => UploadedFile::fake()->createWithContent('report.csv', $header."7,70000\n8,400\n99,0"),
        ])->assertOk()
            ->assertSee('69,999.75')
            ->assertSee('Repayments need review')
            ->assertSee('Loan missing from snapshot')
            ->assertViewHas('unlinked', 1)
            ->assertViewHas('comparison', fn (array $rows): bool => $rows[0]['difference'] === -25
                && $rows[1]['excluded'] === 1
                && $rows[2]['reconstructed'] === null);
    }
}
