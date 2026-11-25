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
            'email' => 'asha@example.com', 'status' => 'active', 'joining_date' => '2026-08-22',
        ]);
        User::factory()->create(['member_id' => $member, 'loan_workflow_roles' => ['board']]);
        $sponsors = [];
        foreach (['SPONSOR-1', 'SPONSOR-2'] as $number) {
            $id = DB::table('members')->insertGetId([
                'first_name' => 'Sponsor', 'last_name' => 'Member', 'registration_no' => $number,
                'email' => "$number@example.com", 'status' => 'active',
            ]);
            $sponsors[$id] = User::factory()->create(['member_id' => $id]);
        }
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
            'member_id' => $member, 'as_of' => '2026-11-21', 'savings_minor' => 100000, 'shares_minor' => 50000000,
            'evidence_reference' => 'Reviewed opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);

        $applications = "/staff/members/$member/loan-applications";
        $this->actingAs($admin)->post($applications, [
            'sponsors' => ['SPONSOR-1', 'SPONSOR-2'],
            'submission_id' => (string) Str::uuid(), 'principal' => '1000.00',
            'pricing_revision_id' => $pricing, 'loan_category_id' => DB::table('loan_categories')->where('name', 'Staff loan')->value('id'), 'term_months' => 1, 'first_due_date' => '2026-12-22',
        ])->assertRedirect();
        $loan = DB::table('loan_applications')->sole()->id;
        $application = "$applications/$loan";
        $recommendation = ['recommendation' => 'approve', 'recommendation_note' => 'Reviewed payment capacity'];
        $this->confirmSponsors($loan, $sponsors);
        $this->actingAs($admin)->post("$application/recommend", $recommendation)->assertRedirect($application);
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
        $this->get("$application/voucher")->assertOk()->assertSee('Loan disbursement voucher')->assertSee('BANK-42');
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
            'sponsors' => ['SPONSOR-1', 'SPONSOR-2'],
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
        $this->confirmSponsors($second, $sponsors);
        $this->actingAs($admin)->post("$applications/$second/recommend", $recommendation)->assertRedirect("$applications/$second");
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

    public function test_a_top_up_clears_the_original_principal_and_due_interest_and_posts_only_the_net_transfer(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(12, 0));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $board = User::factory()->create(['board_role' => 'chairperson']);
        $profile = [
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active', 'joining_date' => '2025-01-01',
        ];
        $member = DB::table('members')->insertGetId($profile);
        User::factory()->create(['member_id' => $member, 'loan_workflow_roles' => ['board']]);
        $sponsors = [];
        foreach (['SPONSOR-1', 'SPONSOR-2'] as $number) {
            $id = DB::table('members')->insertGetId([...$profile, 'registration_no' => $number, 'email' => "$number@example.com"]);
            $sponsors[$id] = User::factory()->create(['member_id' => $id]);
        }
        $category = DB::table('loan_categories')->where('name', 'Staff loan')->value('id');
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
            'member_id' => $member, 'as_of' => '2026-10-01', 'savings_minor' => 100000, 'shares_minor' => 50000000,
            'evidence_reference' => 'Opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $original = DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 100000, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 1000, 'term_months' => 2, 'interest_method' => 'flat', 'loan_category_id' => $category,
            'first_due_date' => '2026-11-01', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        $originalBatch = DB::table('loan_batches')->insertGetId([
            'kind' => 'disbursement', 'status' => 'confirmed', 'items' => '[]',
            'prepared_by' => $admin->id, 'prepared_at' => now(),
        ]);
        DB::table('loan_disbursements')->insert([
            'loan_application_id' => $original, 'loan_batch_id' => $originalBatch, 'cash_amount_minor' => 100000,
            'transfer_reference' => 'ORIGINAL-TRANSFER', 'transferred_on' => '2026-10-01', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('loan_application_installments')->insert([
            ['loan_application_id' => $original, 'number' => 1, 'due_date' => '2026-11-01', 'opening_minor' => 100000,
                'principal_minor' => 50000, 'interest_minor' => 1000, 'payment_minor' => 51000, 'closing_minor' => 50000],
            ['loan_application_id' => $original, 'number' => 2, 'due_date' => '2026-12-01', 'opening_minor' => 50000,
                'principal_minor' => 50000, 'interest_minor' => 1000, 'payment_minor' => 51000, 'closing_minor' => 0],
        ]);

        $path = "/staff/members/$member/loan-applications";
        $payload = [
            'sponsors' => ['SPONSOR-1', 'SPONSOR-2'], 'submission_id' => (string) Str::uuid(),
            'pricing_revision_id' => $pricing, 'loan_category_id' => $category, 'top_up_loan_id' => $original,
            'term_months' => 2, 'first_due_date' => '2026-12-22',
        ];
        $this->actingAs($admin)->get("$path/new?category=$category")->assertOk()->assertSee("Top up loan $original");
        $this->from("$path/new")->post($path, [...$payload, 'principal' => '3000.01'])->assertSessionHasErrors('principal');
        $this->post($path, [...$payload, 'principal' => '1500.00'])->assertRedirect();
        $replacement = DB::table('loan_applications')->where('top_up_loan_id', $original)->sole()->id;
        $this->confirmSponsors($replacement, $sponsors);
        $this->actingAs($admin)->post("$path/$replacement/recommend", ['recommendation' => 'approve', 'recommendation_note' => 'Reviewed'])->assertRedirect();
        $this->actingAs($board)->post("$path/$replacement/review", [
            'decision' => 'approved', 'reason' => 'Top-up approved',
            'minutes' => UploadedFile::fake()->createWithContent('minutes.pdf', "%PDF-1.4\n% Minutes\n%%EOF"),
        ])->assertRedirect();
        $this->actingAs($admin)->post('/staff/loan-batches', ['kind' => 'disbursement', 'loan_ids' => [$replacement]])->assertRedirect();
        $batch = DB::table('loan_batches')->where('id', '<>', $originalBatch)->sole()->id;
        $batchPath = "/staff/loan-batches/$batch";
        $this->actingAs($board)->post("$batchPath/review", ['decision' => 'approved', 'review_note' => 'Transfer checked'])->assertRedirect();
        $confirm = fn (string $net): array => [
            'effective_date' => '2026-11-22', 'references' => [$replacement => 'TOPUP-BANK'], 'net_transfers' => [$replacement => $net],
            'bank_checklist' => UploadedFile::fake()->createWithContent('checklist.pdf', "%PDF-1.4\n% Checklist\n%%EOF"),
        ];
        $this->actingAs($admin)->from($batchPath)->post("$batchPath/confirm", $confirm('500.00'))->assertSessionHasErrors('net_transfer');
        $this->assertDatabaseEmpty('loan_repayment_receipts');

        $this->post("$batchPath/confirm", $confirm('490.00'))->assertRedirect($batchPath);

        $this->assertDatabaseHas('loan_applications', ['id' => $original, 'status' => 'repaid']);
        $this->assertDatabaseHas('loan_applications', ['id' => $replacement, 'status' => 'disbursed']);
        $this->assertDatabaseHas('loan_repayment_receipts', ['loan_application_id' => $original, 'loan_batch_id' => $batch, 'amount_minor' => 101000]);
        $this->assertDatabaseHas('loan_repayment_allocations', ['loan_application_id' => $original, 'installment_number' => 2, 'principal_minor' => 50000, 'interest_minor' => 0]);
        $this->assertDatabaseHas('loan_disbursements', ['loan_application_id' => $replacement, 'cash_amount_minor' => 49000]);
        $this->get($batchPath)->assertOk()->assertSee('Total net bank transfers')->assertViewHas('netTransferTotal', 49000);
        $this->get("$path/$replacement/top-up-voucher")->assertOk()->assertSee('Loan top-up voucher')->assertSee('490.00');

        [$bank, $receivable, $interest] = $this->accounts();
        $journal = "/staff/loan-journal/$batch";
        $accounts = ['cash_account' => $bank, 'principal_account' => $receivable, 'top_up_interest_account' => $interest];
        $this->post("$journal/preview", $accounts)->assertSessionHasNoErrors()->assertRedirect($journal);
        $this->post("$journal/post", ['token' => session('loan_journal_preview.token')])->assertSessionHasNoErrors()->assertRedirect($journal);
        DB::statement('SET CONSTRAINTS loan_journal_entry_balanced, loan_journal_lines_balanced IMMEDIATE');
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $bank, 'signed_minor' => -49000]);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $receivable, 'signed_minor' => 50000]);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $interest, 'signed_minor' => -1000]);
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

    private function confirmSponsors(int $loan, array $sponsors): void
    {
        foreach (DB::table('loan_sponsorships')->where('loan_application_id', $loan)->get() as $nomination) {
            $this->actingAs($sponsors[$nomination->member_id])->post("/member/loan-sponsorships/$nomination->id/confirm")
                ->assertRedirect('/member/account');
        }
    }
}
