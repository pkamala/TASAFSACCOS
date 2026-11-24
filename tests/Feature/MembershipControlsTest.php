<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        $this->actingAs($admin)->from($path)->post("$path/finalize", $finish)->assertSessionHasErrors('exit');
        $this->actingAs($reviewer)->from($path)->post("$path/review", $decision)->assertRedirect($path);
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
        $this->assertDatabaseHas('member_exits', [
            'id' => $exit, 'status' => 'finalized', 'reviewed_by' => $reviewer->id, 'finalized_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('members', ['id' => $member, 'status' => 'exited']);
        $this->assertNull($officer->fresh()->board_role);
        $this->assertDatabaseHas('board_role_changes', ['role' => 'chairperson', 'previous_user_id' => $officer->id, 'next_user_id' => null]);
        $this->actingAs($officer->fresh())->get('/member/account')->assertOk()->assertSee('Finalized')->assertDontSee('Request membership exit');
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

    private function member(?int $legacyId = null): int
    {
        return DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Mushi', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active', 'legacy_member_id' => $legacyId, 'joining_date' => '2026-08-22',
        ]);
    }
}
