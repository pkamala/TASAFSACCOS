<?php

namespace Tests\Feature;

use App\LedgerPeriods;
use App\MemberFunds;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MembershipControlsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_submission_checks_recorded_balances_and_membership_tenure(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $member = $this->member();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        foreach (['SPONSOR-1', 'SPONSOR-2'] as $number) {
            $id = DB::table('members')->insertGetId([
                'first_name' => 'Sponsor', 'last_name' => 'Member', 'registration_no' => $number,
                'email' => "$number@example.com", 'status' => 'active',
            ]);
            User::factory()->create(['member_id' => $id]);
        }
        $minimums = '/staff/membership-minimums';
        $thresholds = ['savings' => '100000', 'shares' => '50000', 'current_revision' => 0, 'reason' => 'Board policy'];

        $this->actingAs(User::factory()->create())->patch($minimums, $thresholds)->assertForbidden();
        $this->actingAs($admin)->from($minimums)->patch($minimums, $thresholds)->assertRedirect($minimums);
        $this->get($minimums)->assertOk()->assertSee('Board policy');
        $revision = DB::table('loan_pricing_revisions')->insertGetId([
            'annual_basis_points' => 1000, 'interest_method' => 'flat', 'insurance_basis_points' => 0,
            'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'changed_by' => $admin->id, 'reason' => 'Test pricing', 'changed_at' => now(),
        ]);
        $path = "/staff/members/$member/loan-applications";
        $payload = [
            'sponsors' => ['SPONSOR-1', 'SPONSOR-2'],
            'submission_id' => (string) Str::uuid(), 'principal' => '300000', 'pricing_revision_id' => $revision, 'loan_category_id' => DB::table('loan_categories')->where('name', 'Staff loan')->value('id'),
            'term_months' => 6, 'first_due_date' => '2026-12-31',
        ];

        $this->get("$path/new")->assertOk()->assertSee('Submit application')->assertDontSee('No opening savings');
        $this->getJson('/staff/tasks/count', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSessionHas('_previous.url', url("$path/new"));
        $this->from("$path/new")->post($path, $payload)->assertRedirect("$path/new")->assertSessionHasErrors([
            'eligibility' => 'No opening savings and shares have been imported for this member.',
        ]);

        $balance = [
            'member_id' => $member, 'as_of' => '2026-11-21', 'savings_minor' => 10000000, 'shares_minor' => 49999999,
            'evidence_reference' => 'Statement 01', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ];
        DB::table('member_verified_balances')->insert($balance);
        $this->from("$path/new")->post($path, $payload)->assertSessionHasErrors([
            'eligibility' => 'Share balance is below the required minimum.',
        ]);
        $this->assertDatabaseEmpty('loan_applications');

        DB::table('member_verified_balances')->insert([...$balance, 'shares_minor' => 50000000, 'evidence_reference' => 'Statement 02']);
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 21));
        $this->from("$path/new")->post($path, $payload)->assertSessionHasErrors([
            'eligibility' => 'The member completes three months of membership on 2026-11-22.',
        ]);

        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $this->post($path, $payload)->assertRedirect();
        $this->assertDatabaseHas('loan_applications', [
            'member_id' => $member,
            'loan_eligibility_revision_id' => DB::table('loan_eligibility_revisions')->sole()->id,
            'member_verified_balance_id' => DB::table('member_verified_balances')->where('evidence_reference', 'Statement 02')->sole()->id,
        ]);
    }

    public function test_member_exit_requires_board_approval_and_settlement_evidence(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $member = $this->member(53);
        $officer = User::factory()->create(['member_id' => $member, 'board_role' => 'chairperson']);
        $reviewer = User::factory()->create(['board_role' => 'secretary']);
        $admin = User::factory()->create(['is_staff_admin' => true]);

        $this->actingAs($officer)->post('/member/exit', ['reason' => 'Leaving the cooperative'])->assertRedirect('/member/account');
        $exit = DB::table('member_exits')->sole()->id;
        $path = "/staff/member-exits/$exit";
        $decision = ['decision' => 'approved', 'review_note' => 'Settlement to be confirmed'];
        $finish = ['effective_on' => '2026-11-22', 'settlement_reference' => 'Settlement 01', 'legacy_clearance_reference' => 'Legacy clearance 01'];

        $this->post("$path/review", $decision)->assertForbidden();
        $this->actingAs($admin)->get($path)->assertOk()->assertSee('A verified opening balance is needed');
        $this->actingAs($admin)->from($path)->post("$path/finalize", $finish)->assertSessionHasErrors('exit');
        $this->actingAs($reviewer)->from($path)->post("$path/review", $decision)->assertRedirect($path);
        $this->get("$path/voucher")->assertOk()->assertSee('Draft membership exit voucher')->assertSee('A verified opening balance is needed');
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-11-01', 'savings_minor' => 100000, 'shares_minor' => 20000,
            'evidence_reference' => 'Opening statement', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $this->get("$path/voucher")->assertOk()->assertSee('Print voucher')->assertSee('1,180.00');
        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 500000, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 1000, 'term_months' => 6, 'interest_method' => 'flat',
            'first_due_date' => '2026-12-31', 'status' => 'approved', 'created_at' => now(),
        ]);
        $loanPath = "/staff/members/$member/loan-applications/$loan";
        $this->actingAs($admin)->from($path)->post("$path/finalize", $finish)->assertSessionHasErrors('exit');
        $this->post("$loanPath/cancel-for-exit", ['reason' => 'Exit clearance'])->assertForbidden();
        $this->actingAs($reviewer)->get($loanPath)->assertOk()->assertSee('Cancel undisbursed loan');
        $this->post("$loanPath/cancel-for-exit", ['reason' => 'Exit clearance'])->assertRedirect($loanPath);
        $this->assertDatabaseHas('loan_exit_cancellations', ['loan_application_id' => $loan, 'member_exit_id' => $exit, 'cancelled_by' => $reviewer->id]);
        $this->assertDatabaseHas('loan_applications', ['id' => $loan, 'status' => 'cancelled']);
        $this->assertDatabaseHas('members', ['id' => $member, 'status' => 'active']);
        $this->post("$path/finalize", $finish)->assertForbidden();
        $this->actingAs($admin)->from($path)->post("$path/finalize", [...$finish, 'legacy_clearance_reference' => null])->assertSessionHasErrors('exit');
        $this->from($path)->post("$path/finalize", $finish)->assertRedirect($path);
        DB::table('member_verified_balances')->where('member_id', $member)->update(['savings_minor' => 200000]);
        $this->get("$path/voucher")->assertOk()->assertSee('Settlement 01')->assertSee('1,180.00')->assertDontSee('Draft membership exit voucher');
        $this->assertDatabaseHas('member_exits', [
            'id' => $exit, 'status' => 'finalized', 'reviewed_by' => $reviewer->id, 'finalized_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('members', ['id' => $member, 'status' => 'exited']);
        $this->assertNull($officer->fresh()->board_role);
        $this->assertDatabaseHas('board_role_changes', ['role' => 'chairperson', 'previous_user_id' => $officer->id, 'next_user_id' => null]);
        $this->actingAs($officer->fresh())->get('/member/account')->assertOk()->assertSee('Finalized')->assertDontSee('Request membership exit');
    }

    public function test_exit_draft_deducts_unpaid_principal_and_only_current_month_interest(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $member = $this->member();
        $login = User::factory()->create(['member_id' => $member]);
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $this->actingAs($login)->post('/member/exit', ['reason' => 'Leaving the cooperative'])->assertRedirect('/member/account');
        $exit = DB::table('member_exits')->sole()->id;
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-11-01', 'savings_minor' => 100000, 'shares_minor' => 20000,
            'evidence_reference' => 'Opening statement', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 200000, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 1200, 'term_months' => 2, 'interest_method' => 'flat',
            'first_due_date' => '2026-11-30', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        DB::table('loan_application_installments')->insert([
            ['loan_application_id' => $loan, 'number' => 1, 'due_date' => '2026-11-30', 'opening_minor' => 200000,
                'principal_minor' => 100000, 'interest_minor' => 2000, 'payment_minor' => 102000, 'closing_minor' => 100000],
            ['loan_application_id' => $loan, 'number' => 2, 'due_date' => '2026-12-31', 'opening_minor' => 100000,
                'principal_minor' => 100000, 'interest_minor' => 2000, 'payment_minor' => 102000, 'closing_minor' => 0],
        ]);
        $path = "/staff/member-exits/$exit";
        $this->actingAs($admin)->get($path)->assertOk()->assertViewHas('draft', [
            'savings' => 100000, 'shares' => 20000, 'fee' => 2000,
            'principal' => 200000, 'loanInterest' => 2000, 'subtotal' => -84000,
            'interest' => ['gross' => 0, 'withholding' => 0, 'net' => 0, 'payout' => -84000, 'annualRate' => 600, 'withholdingRate' => 1000],
        ]);

        $receipt = DB::table('loan_repayment_receipts')->insertGetId([
            'loan_application_id' => $loan, 'receipt_reference' => 'Partial repayment', 'received_on' => '2026-11-20',
            'amount_minor' => 27000, 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('loan_repayment_allocations')->insert([
            'loan_application_id' => $loan, 'loan_repayment_receipt_id' => $receipt, 'installment_number' => 1,
            'principal_minor' => 25000, 'interest_minor' => 2000,
        ]);

        $response = $this->get($path);

        $response->assertOk()
            ->assertViewHas('draft', [
                'savings' => 100000, 'shares' => 20000, 'fee' => 2000,
                'principal' => 175000, 'loanInterest' => 0, 'subtotal' => -57000,
                'interest' => ['gross' => 0, 'withholding' => 0, 'net' => 0, 'payout' => -57000, 'annualRate' => 600, 'withholdingRate' => 1000],
            ])
            ->assertSee('Settlement shortfall (TZS)')->assertSee('570.00');
    }

    public function test_exit_interest_uses_each_completed_month_of_the_calendar_year_and_configured_withholding(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2027, 3, 22));
        $member = $this->member();
        $login = User::factory()->create(['member_id' => $member]);
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $this->actingAs($login)->post('/member/exit', ['reason' => 'Leaving the cooperative'])->assertRedirect();
        $exit = DB::table('member_exits')->sole()->id;
        $opening = DB::table('member_verified_balances')->insertGetId([
            'member_id' => $member, 'as_of' => '2026-12-01', 'savings_minor' => 50000, 'shares_minor' => 20000,
            'evidence_reference' => 'Opening statement', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('member_fund_transactions')->insert([
            'member_id' => $member, 'member_verified_balance_id' => $opening, 'kind' => 'savings_deposit',
            'signed_minor' => 50000, 'effective_on' => '2026-12-31', 'reference' => 'December savings',
            'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('member_fund_transactions')->insert([
            'member_id' => $member, 'member_verified_balance_id' => $opening, 'kind' => 'savings_deposit',
            'signed_minor' => 100000, 'effective_on' => '2027-02-28', 'reference' => 'February savings',
            'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('member_fund_transactions')->insert([
            'member_id' => $member, 'member_verified_balance_id' => $opening, 'kind' => 'savings_deposit',
            'signed_minor' => 200000, 'effective_on' => '2027-03-01', 'reference' => 'March savings',
            'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $settings = '/staff/exit-settlement-rates';
        $rates = ['current_revision' => 0, 'savings_interest_rate' => '6', 'withholding_rate' => '20'];
        $this->get($settings)->assertForbidden();
        $this->patch($settings, $rates)->assertForbidden();

        $path = "/staff/member-exits/$exit";
        $this->actingAs($admin)->get($path)->assertOk()->assertViewHas('draft', fn (array $draft): bool => $draft['interest'] === [
            'gross' => 1500, 'withholding' => 150, 'net' => 1350, 'payout' => 413350,
            'annualRate' => 600, 'withholdingRate' => 1000,
        ]);
        $this->from($settings)->patch($settings, [...$rates, 'withholding_rate' => '101'])->assertSessionHasErrors('withholding_rate');
        $this->patch($settings, $rates)->assertRedirect($settings);

        $this->get($path)->assertOk()->assertViewHas('draft', fn (array $draft): bool => $draft['interest'] === [
            'gross' => 1500, 'withholding' => 300, 'net' => 1200, 'payout' => 413200,
            'annualRate' => 600, 'withholdingRate' => 2000,
        ])->assertSee('Draft payout');

        $revision = DB::table('exit_settlement_rate_revisions')->sole()->id;
        $this->get($settings)->assertOk()->assertSee('Withholding on gross savings interest');
        $this->from($settings)->patch($settings, $rates)->assertSessionHasErrors('current_revision');
        $this->patch($settings, [...$rates, 'current_revision' => $revision, 'savings_interest_rate' => '12'])
            ->assertRedirect($settings);
        $this->get($path)->assertOk()->assertViewHas('draft', fn (array $draft): bool => $draft['interest'] === [
            'gross' => 3000, 'withholding' => 600, 'net' => 2400, 'payout' => 414400,
            'annualRate' => 1200, 'withholdingRate' => 2000,
        ]);
    }

    public function test_only_admins_can_inactivate_and_reactivate_members(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $member = $this->member();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $login = User::factory()->create(['member_id' => $member]);
        $profile = "/staff/members/$member";
        $change = ['membership_status' => 'inactive', 'status_reason' => 'Administrative suspension'];

        $this->actingAs($login)->patch("$profile/status", $change)->assertForbidden();
        $this->get('/member/account')->assertOk()->assertSee('Joined 22 Aug 2026')->assertSee('3 months');
        $role = ['loan_workflow_roles' => ['staff', 'committee'], 'role_reason' => 'Assign loan intake'];
        $this->patch("$profile/loan-role", $role)->assertForbidden();
        $this->actingAs($admin)->patch("$profile/loan-role", $role)->assertRedirect($profile);
        $this->get($profile)->assertOk()->assertSee('None → Loan intake staff, Loan committee member');
        $assigned = $login->fresh();
        $this->assertTrue($assigned->hasLoanRole('staff'));
        $this->assertTrue($assigned->hasLoanRole('committee'));
        $this->actingAs($assigned)->get('/staff/loan-intake')->assertOk();
        $this->get('/staff/tasks')->assertOk();

        $this->actingAs($admin)->patch("$profile/status", $change)->assertRedirect($profile);
        $this->actingAs($login)->get('/staff/loan-intake')->assertForbidden();
        $this->actingAs($admin);

        $this->assertDatabaseHas('members', ['id' => $member, 'status' => 'inactive']);
        $this->get('/staff/members?status=inactive')->assertOk()->assertSee('10415');
        $this->get('/staff/members?status=active')->assertOk()->assertDontSee('10415');
        $this->get("$profile/loan-applications/new")->assertForbidden();

        $this->patch("$profile/status", [...$change, 'membership_status' => 'exited'])->assertSessionHasErrors('membership_status');
        $this->patch("$profile/status", [...$change, 'membership_status' => 'active'])->assertRedirect($profile);
        $this->assertDatabaseHas('members', ['id' => $member, 'status' => 'active']);
    }

    public function test_an_exited_member_rejoins_with_a_new_membership_and_keeps_their_login(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 27));
        $member = $this->member();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $login = User::factory()->create(['member_id' => $member, 'email' => 'asha@example.com', 'loan_workflow_roles' => ['staff']]);
        $profile = "/staff/members/$member";
        $input = ['rejoin_joining_date' => '2026-11-27'];

        $this->actingAs($login)->post("$profile/rejoin", $input)->assertForbidden();
        $this->actingAs($admin)->post("$profile/rejoin", $input)->assertSessionHasErrors('rejoin');

        DB::table('members')->where('id', $member)->update(['status' => 'exited', 'payroll_savings_minor' => 50000]);
        foreach (['9999', '020000', 'LEGACY-99999'] as $number) {
            DB::table('members')->insert([
                'first_name' => 'Other', 'last_name' => 'Member', 'registration_no' => $number,
                'email' => $number.'@example.com', 'status' => 'exited',
            ]);
        }
        DB::table('member_exits')->insert([
            'member_id' => $member, 'status' => 'finalized', 'requested_by' => $admin->id,
            'requested_at' => now(), 'reason' => 'Resignation', 'effective_on' => '2026-11-26',
        ]);

        $this->get($profile)->assertOk()->assertSee('Register new membership');
        $this->post("$profile/rejoin", [...$input, 'rejoin_joining_date' => '2026-11-25'])->assertSessionHasErrors('rejoin_joining_date');

        $response = $this->post("$profile/rejoin", [...$input, 'rejoin_registration_no' => '50000']);
        $newMember = DB::table('members')->where('previous_membership_id', $member)->value('id');
        $response->assertRedirect("/staff/members/$newMember");
        $this->assertDatabaseHas('members', [
            'id' => $newMember, 'previous_membership_id' => $member, 'registration_no' => '20001',
            'joining_date' => '2026-11-27', 'status' => 'active', 'email' => 'asha@example.com', 'payroll_savings_minor' => 0,
        ]);
        $this->assertDatabaseHas('members', [
            'id' => $member, 'registration_no' => '10415', 'joining_date' => '2026-08-22',
            'status' => 'exited', 'payroll_savings_minor' => 50000,
        ]);
        $this->assertDatabaseHas('member_exits', ['member_id' => $member, 'status' => 'finalized']);
        $this->assertDatabaseMissing('member_exits', ['member_id' => $newMember]);
        $this->assertDatabaseHas('users', ['id' => $login->id, 'member_id' => $newMember]);
        $this->assertSame([], $login->fresh()->loan_workflow_roles);
        $this->assertDatabaseHas('member_audits', ['member_id' => $member, 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('member_audits', ['member_id' => $newMember, 'actor_id' => $admin->id]);
        $this->get($profile)->assertOk()->assertSee('New membership 20001')->assertDontSee('Register new membership');
        $this->get("/staff/members/$newMember")->assertOk()->assertSee('Previous membership 10415');
        $this->post("$profile/rejoin", $input)->assertSessionHasErrors('rejoin');
        $this->actingAs($login->fresh())->get('/member/account')->assertOk()->assertSee('20001');
    }

    public function test_shared_password_configuration_runs_only_in_demo_and_preserves_admin_passwords(): void
    {
        $member = $this->member();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $registered = DB::table('members')->insertGetId([
            'first_name' => 'Existing', 'last_name' => 'Member', 'registration_no' => 'EXISTING',
            'email' => 'existing@example.com', 'status' => 'active',
        ]);
        $existing = User::factory()->create(['member_id' => $registered, 'local_setup_pending' => true]);
        $password = 'Shared-demo-test-password';

        config(['app.demo_mode' => false]);
        $this->artisan('members:set-demo-passwords')->assertFailed();
        $this->assertDatabaseMissing('users', ['member_id' => $member]);

        config(['app.demo_mode' => true]);
        $this->artisan('members:set-demo-passwords')
            ->expectsQuestion('Demo member password', $password)->assertSuccessful();

        $created = User::query()->where('member_id', $member)->sole();
        $this->assertTrue(Hash::check($password, $created->password));
        $this->assertTrue(Hash::check($password, $existing->fresh()->password));
        $this->assertFalse($existing->fresh()->local_setup_pending);
        $this->assertSame($admin->password, $admin->fresh()->password);
    }

    #[TestWith([150000, 0, 34000, 'disbursed'])]
    #[TestWith([50000, 66000, 0, 'repaid'])]
    public function test_approved_exit_posts_a_balanced_offset_and_retains_any_residual_balance(int $principal, int $payable, int $outstanding, string $loanStatus): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $member = $this->member();
        $owner = User::factory()->create(['member_id' => $member]);
        $reviewer = User::factory()->create(['board_role' => 'secretary']);
        $admin = User::factory()->create(['is_staff_admin' => true]);
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-11-01', 'savings_minor' => 100000, 'shares_minor' => 20000,
            'evidence_reference' => 'Opening statement', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => $principal, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 1200, 'term_months' => 2, 'interest_method' => 'flat',
            'first_due_date' => '2026-10-31', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        $half = intdiv($principal, 2);
        DB::table('loan_application_installments')->insert([
            ['loan_application_id' => $loan, 'number' => 1, 'due_date' => '2026-10-31', 'opening_minor' => $principal,
                'principal_minor' => $half, 'interest_minor' => 2000, 'payment_minor' => $half + 2000, 'closing_minor' => $half],
            ['loan_application_id' => $loan, 'number' => 2, 'due_date' => '2026-12-31', 'opening_minor' => $half,
                'principal_minor' => $half, 'interest_minor' => 2000, 'payment_minor' => $half + 2000, 'closing_minor' => 0],
        ]);
        $transferBatch = DB::table('loan_batches')->insertGetId([
            'kind' => 'disbursement', 'status' => 'confirmed', 'items' => '[]', 'prepared_by' => $admin->id, 'prepared_at' => now(),
        ]);
        DB::table('loan_disbursements')->insert([
            'loan_application_id' => $loan, 'loan_batch_id' => $transferBatch, 'cash_amount_minor' => $principal,
            'transfer_reference' => 'ORIGINAL-TRANSFER', 'transferred_on' => '2026-10-01',
            'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Settlement']);
        $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Settlement', 'account_category_id' => $category]);
        $accounts = [];
        foreach (['savings', 'shares', 'fee', 'loan_principal', 'loan_interest', 'payout'] as $index => $kind) {
            $accounts[$kind] = DB::table('chart_accounts')->insertGetId([
                ...$source, 'legacy_id' => $index + 1, 'name' => $kind, 'code' => (string) ($index + 1),
                'account_type_id' => $type, 'is_ledger_account' => true,
            ]);
        }
        $this->actingAs($owner)->post('/member/exit', ['reason' => 'Leaving'])->assertRedirect();
        $exit = DB::table('member_exits')->sole()->id;
        $path = "/staff/member-exits/$exit";
        $this->actingAs($reviewer)->post("$path/review", ['decision' => 'approved', 'review_note' => 'Reviewed'])->assertRedirect();
        $this->get("$path/settlement")->assertForbidden();
        $review = $this->actingAs($admin)->get("$path/settlement")->assertOk()
            ->assertViewHas('plan', fn (array $plan): bool => $plan['payable'] === $payable && $plan['outstanding'] === $outstanding);
        $input = ['fingerprint' => $review['fingerprint'], 'accounts' => $accounts];
        $this->from("$path/settlement")->post("$path/settlement", [...$input, 'fingerprint' => str_repeat('0', 64)])
            ->assertSessionHasErrors('exit');
        $this->assertDatabaseEmpty('general_journals');

        $this->post("$path/settlement", $input)->assertRedirect("$path/voucher");

        $this->assertDatabaseHas('members', ['id' => $member, 'status' => 'exited']);
        $this->assertDatabaseHas('loan_applications', ['id' => $loan, 'status' => $loanStatus]);
        $this->assertDatabaseHas('loan_repayment_receipts', ['loan_application_id' => $loan, 'receipt_reference' => "EXIT-$exit", 'amount_minor' => 118000 - $payable]);
        $this->assertDatabaseHas('loan_repayment_allocations', ['loan_application_id' => $loan, 'installment_number' => 1, 'interest_minor' => 2000]);
        $this->assertDatabaseHas('loan_repayment_allocations', ['loan_application_id' => $loan, 'installment_number' => 2, 'interest_minor' => 0]);
        $this->assertSame(0, app(MemberFunds::class)->balances($member)['savings']);
        $this->assertSame(0, app(MemberFunds::class)->balances($member)['shares']);
        $this->assertSame(0, app(LedgerPeriods::class)->unposted('2026-11-01', '2026-11-30')['loan_repayments']);
        $this->assertSame(0, app(LedgerPeriods::class)->unposted('2026-11-01', '2026-11-30')['member_funds']);
        $journal = DB::table('general_journals')->sole()->id;
        DB::statement('SET CONSTRAINTS general_journal_balanced, general_journal_lines_balanced IMMEDIATE');
        $this->assertSame(0, (int) DB::table('general_journal_lines')->where('general_journal_id', $journal)->sum('signed_minor'));
        if ($payable > 0) {
            $this->assertDatabaseHas('general_journal_lines', ['general_journal_id' => $journal, 'chart_account_id' => $accounts['payout'], 'signed_minor' => -$payable]);
        }
        $this->get("$path/voucher")->assertOk()->assertSee('View posted settlement journal')->assertSee('Outstanding loan balance after offset');
        $this->get("/staff/general-journals/$journal/voucher")->assertOk()->assertSee('General journal voucher')->assertSee("EXIT-$exit");
        $this->get("/staff/ledger-reversals/general/$journal")->assertStatus(409);
        $this->post("$path/settlement", $input)->assertRedirect("$path/voucher");
        $this->assertDatabaseCount('general_journals', 1);
    }

    private function member(?int $legacyId = null): int
    {
        return DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Mushi', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active', 'legacy_member_id' => $legacyId, 'joining_date' => '2026-08-22',
        ]);
    }
}
