<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerifiedLegacyLoanBalanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_member_statement_uses_legacy_principal_only_after_all_applicable_loans_are_verified(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $snapshot = str_repeat('b', 64);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'member@example.com', 'status' => 'active',
            'legacy_member_id' => 53, 'legacy_source_sha256' => $snapshot,
        ]);
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-08-31', 'savings_minor' => 100000, 'shares_minor' => 0,
            'evidence_reference' => 'Opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        foreach ([7 => '2026-08-01', 8 => null, 9 => '2026-11-01'] as $id => $date) {
            DB::table('legacy_onboarding_rows')->insert([
                'source_sha256' => $snapshot, 'source_table' => 'loans', 'source_id' => $id,
                'data' => json_encode(['member_id' => '53', 'loan_code' => "LOAN-$id", 'disbursement_date' => $date], JSON_THROW_ON_ERROR),
                'issues' => '[]',
            ]);
        }

        $path = '/staff/verified-legacy-loans';
        $statement = '/staff/statement-drafts';
        $header = "legacy_loan_id,principal_balance_tzs\n";
        $file = fn (string $rows): UploadedFile => UploadedFile::fake()->createWithContent('report.csv', $header.$rows);
        $input = ['snapshot' => $snapshot, 'period' => '2026-10', 'evidence' => 'Signed October reconciliation'];

        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->post("$path/preview")->assertForbidden();
        $this->actingAs($admin)->get($statement)->assertOk()->assertSee('Legacy loans need reconciliation');

        $this->post("$path/preview", [...$input, 'report' => $file("9,1\n")])->assertRedirect("$path#preview");
        $this->get($path)->assertOk()->assertSee('Disbursement is after the report date');
        $this->from($path)->post("$path/apply", ['token' => session('verified_legacy_loans_preview.token')])->assertSessionHasErrors('report');
        $this->assertDatabaseEmpty('verified_legacy_loan_balances');

        $first = "7,125.50\n";
        $this->post("$path/preview", [...$input, 'report' => $file($first)])->assertRedirect("$path#preview");
        $token = session('verified_legacy_loans_preview.token');
        $this->assertDatabaseEmpty('verified_legacy_loan_balances');
        $this->post("$path/apply", ['token' => $token])->assertRedirect($path);
        $this->assertDatabaseHas('verified_legacy_loan_balances', [
            'source_id' => 7, 'member_id' => $member, 'as_of' => '2026-10-31', 'principal_minor' => 12550,
            'evidence_reference' => $input['evidence'], 'verified_by' => $admin->id, 'report_sha256' => hash('sha256', $header.$first),
        ]);
        $this->get($statement)->assertOk()->assertSee('Legacy loans need reconciliation');

        $this->post("$path/preview", [...$input, 'report' => $file("7,126\n")])->assertRedirect("$path#preview");
        $this->get($path)->assertOk()->assertSee('A different balance is already verified for this month');
        $this->post("$path/preview", [...$input, 'report' => $file("8,0\n")])->assertRedirect("$path#preview");
        $this->post("$path/apply", ['token' => session('verified_legacy_loans_preview.token')])->assertRedirect($path);
        $this->assertDatabaseCount('verified_legacy_loan_balances', 2);
        $this->get($path)->assertOk()->assertSee('Signed October reconciliation')->assertSee($admin->name);
        $this->get($statement)->assertOk()->assertSee('1 ready for finance review')->assertSee('125.50')->assertDontSee('Legacy loans need reconciliation');
        $download = $this->get("$statement/download?period=2026-10")->assertDownload('monthly-statement-draft-2026-10.csv');
        $this->assertStringContainsString('10415,member@example.com,125.50,0.00,1000.00', $download->streamedContent());
        $this->get("$statement?period=2026-09")->assertOk()->assertSee('Legacy loans need reconciliation');
        $this->assertDatabaseEmpty('monthly_member_statements');
    }
}
