<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoanLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_submitted_loan_flows_through_board_approval_bank_transfer_payroll_and_the_ledger(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(12, 0));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $chair = User::factory()->create(['board_role' => 'chairperson']);
        $secretary = User::factory()->create(['board_role' => 'secretary']);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active',
        ]);
        $pricing = DB::table('loan_pricing_revisions')->insertGetId([
            'annual_basis_points' => 1000, 'interest_method' => 'flat', 'insurance_basis_points' => 0,
            'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'changed_by' => $admin->id, 'reason' => 'Approved pricing', 'changed_at' => now(),
        ]);
        DB::table('loan_eligibility_revisions')->insert([
            'min_savings_minor' => 1000, 'min_shares_minor' => 2000, 'changed_by' => $admin->id,
            'reason' => 'Approved minimums', 'changed_at' => now(),
        ]);
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-11-21', 'savings_minor' => 1000, 'shares_minor' => 2000,
            'evidence_reference' => 'Reviewed opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);

        $applications = "/staff/members/$member/loan-applications";
        $this->actingAs($admin)->post($applications, [
            'submission_id' => (string) Str::uuid(), 'principal' => '1000.00',
            'pricing_revision_id' => $pricing, 'loan_category_id' => DB::table('loan_categories')->where('name', 'Staff loan')->value('id'), 'term_months' => 1, 'first_due_date' => '2026-12-22',
        ])->assertRedirect();
        $loan = DB::table('loan_applications')->sole()->id;
        $application = "$applications/$loan";
        $minutes = "%PDF-1.4\n% Board minutes\n%%EOF";
        $this->actingAs($chair)->post("$application/review", [
            'decision' => 'approved', 'reason' => 'November board meeting',
            'minutes' => UploadedFile::fake()->createWithContent('minutes.pdf', $minutes),
        ])->assertRedirect($application);

        $batches = '/staff/loan-batches';
        $this->actingAs($admin)->post($batches, ['kind' => 'disbursement', 'loan_ids' => [$loan]])->assertRedirect();
        $disbursement = DB::table('loan_batches')->where('kind', 'disbursement')->sole()->id;
        $bankBatch = "$batches/$disbursement";
        $this->actingAs($secretary)->post("$bankBatch/review", ['decision' => 'approved', 'review_note' => 'Checked disbursement list'])
            ->assertRedirect($bankBatch);
        $bankChecklist = "%PDF-1.4\n% Bank checklist\n%%EOF";
        $this->actingAs($admin)->post("$bankBatch/confirm", [
            'effective_date' => '2026-11-22', 'references' => [$loan => 'BANK-42'],
            'bank_checklist' => UploadedFile::fake()->createWithContent('checklist.pdf', $bankChecklist),
        ])->assertRedirect($bankBatch);
        $this->get("$application/minutes")->assertOk()->assertContent($minutes);
        $this->get("$bankBatch/checklist")->assertOk()->assertContent($bankChecklist);
        $this->assertDatabaseHas('loan_applications', ['id' => $loan, 'status' => 'disbursed']);

        $emergency = DB::table('loan_categories')->where('name', 'Emergency loan')->value('id');
        $this->get("$applications/new")->assertOk()->assertSee('Other active loans for this member: 1');
        $emergencyPricing = DB::table('loan_pricing_revisions')->insertGetId([
            'loan_category_id' => $emergency, 'annual_basis_points' => 1500, 'interest_method' => 'reducing',
            'insurance_basis_points' => 100, 'processing_fee_minor' => 1000, 'other_charges_minor' => 200,
            'changed_by' => $admin->id, 'reason' => 'Emergency terms', 'changed_at' => now(),
        ]);
        $this->get("$applications/new?category=$emergency")->assertOk()->assertSee('15.00% annual interest');
        $secondApplication = [
            'submission_id' => (string) Str::uuid(), 'principal' => '250.00', 'pricing_revision_id' => $pricing,
            'loan_category_id' => $emergency, 'term_months' => 1, 'first_due_date' => '2027-01-22',
        ];
        $this->from("$applications/new?category=$emergency")->post($applications, $secondApplication)
            ->assertSessionHasErrors('pricing_revision_id');
        $this->post($applications, [...$secondApplication, 'pricing_revision_id' => $emergencyPricing])->assertRedirect();
        $this->assertDatabaseHas('loan_applications', ['id' => $loan, 'loan_pricing_revision_id' => $pricing, 'annual_basis_points' => 1000]);
        $second = DB::table('loan_applications')->where('loan_category_id', $emergency)->sole()->id;
        $this->assertDatabaseHas('loan_applications', [
            'id' => $second, 'loan_pricing_revision_id' => $emergencyPricing, 'annual_basis_points' => 1500,
            'insurance_minor' => 250, 'processing_fee_minor' => 1000, 'other_charges_minor' => 200,
        ]);
        $this->actingAs($chair)->post("$applications/$second/review", [
            'decision' => 'approved', 'reason' => 'Emergency loan reviewed',
            'minutes' => UploadedFile::fake()->createWithContent('minutes.pdf', $minutes),
        ])->assertRedirect();
        $this->actingAs($admin)->post($batches, ['kind' => 'disbursement', 'loan_ids' => [$second]])->assertRedirect();
        $secondBatch = DB::table('loan_batches')->where('id', '<>', $disbursement)->sole()->id;
        $this->actingAs($secretary)->post("$batches/$secondBatch/review", ['decision' => 'approved', 'review_note' => 'Checked emergency loan'])->assertRedirect();
        $this->actingAs($admin)->post("$batches/$secondBatch/confirm", [
            'effective_date' => '2026-11-22', 'references' => [$second => 'BANK-43'],
            'bank_checklist' => UploadedFile::fake()->createWithContent('checklist.pdf', $bankChecklist),
        ])->assertRedirect();
        $this->assertSame(2, DB::table('loan_applications')->where('member_id', $member)->where('status', 'disbursed')->count());
        $this->get("/staff/loan-register?status=disbursed&category=$emergency")->assertOk()
            ->assertViewHas('loans', fn ($loans): bool => $loans->pluck('id')->all() === [$second]);

        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 12, 22)->setTime(12, 0));
        $this->post($batches, [
            'kind' => 'repayment', 'period' => '2026-12',
        ])->assertRedirect();
        $repayment = DB::table('loan_batches')->where('kind', 'repayment')->sole()->id;
        $payrollBatch = "$batches/$repayment";
        $this->actingAs($chair)->post("$payrollBatch/review", ['decision' => 'approved', 'review_note' => 'December payroll approved'])
            ->assertRedirect($payrollBatch);
        $this->assertDatabaseEmpty('loan_repayment_receipts');
        $this->actingAs($admin)->post("$payrollBatch/confirm", ['effective_date' => '2026-12-22', 'payroll_reference' => 'PAYROLL-12'])
            ->assertRedirect($payrollBatch);
        $this->get('/staff/loan-register?status=repaid')->assertOk()->assertSee('10415');

        $this->assertDatabaseHas('loan_application_decisions', ['loan_application_id' => $loan, 'reviewed_by' => $chair->id]);
        foreach ([$disbursement => $secretary, $repayment => $chair] as $id => $reviewer) {
            $this->assertDatabaseHas('loan_batches', [
                'id' => $id, 'status' => 'confirmed', 'prepared_by' => $admin->id,
                'reviewed_by' => $reviewer->id, 'confirmed_by' => $admin->id,
            ]);
        }
        $this->assertDatabaseHas('loan_repayment_receipts', [
            'loan_application_id' => $loan, 'receipt_reference' => 'PAYROLL-12', 'amount_minor' => 100833, 'recorded_by' => $admin->id,
        ]);

        [$bank, $receivable, $interest] = $this->accounts();
        foreach ([$disbursement, $repayment] as $batch) {
            $journal = "/staff/loan-journal/$batch";
            $this->post("$journal/preview", [
                'cash_account' => $bank, 'principal_account' => $receivable,
                ...($batch === $repayment ? ['offset_account' => $interest] : []),
            ])->assertRedirect($journal);
            $this->post("$journal/post", ['token' => session('loan_journal_preview.token')])->assertRedirect($journal);
        }
        DB::statement('SET CONSTRAINTS loan_journal_entry_balanced, loan_journal_lines_balanced IMMEDIATE');
        $this->get("/staff/loan-ledger/accounts/$receivable?from=2026-11-01&to=2026-12-22")
            ->assertOk()->assertSee('BANK-42')->assertSee('PAYROLL-12')->assertViewHas('closingBalance', 0);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $interest, 'signed_minor' => -833]);
    }

    private function accounts(): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Test category']);
        $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Test type', 'account_category_id' => $category]);

        return array_map(fn (int $id, string $name): int => DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => (string) $id, 'name' => $name, 'is_ledger_account' => true,
        ]), [1, 2, 3], ['Bank', 'Loan receivable', 'Interest income']);
    }
}
