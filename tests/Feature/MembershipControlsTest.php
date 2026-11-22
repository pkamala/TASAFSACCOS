<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MembershipControlsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_members_need_verified_savings_and_shares_to_submit_a_loan(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $member = $this->member();
        $admin = User::factory()->create(['is_staff_admin' => true]);
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
        $upload = "/staff/members/$member/verified-balances";
        $payload = [
            'submission_id' => (string) Str::uuid(), 'principal' => '500000', 'pricing_revision_id' => $revision, 'loan_category_id' => DB::table('loan_categories')->where('name', 'Staff loan')->value('id'),
            'term_months' => 6, 'first_due_date' => '2026-12-31',
        ];

        $this->from("$path/new")->post($path, $payload)->assertSessionHasErrors('eligibility');
        $balance = ['as_of' => '2026-11-22', 'savings' => '100000', 'shares' => '49999.99', 'evidence_reference' => 'Statement 01'];
        $this->from($upload)->post($upload, $balance)->assertRedirect($upload);
        $this->get("$path/new")->assertOk()->assertSee('Verified shares are below the required minimum.');
        $this->from("$path/new")->post($path, $payload)->assertSessionHasErrors('eligibility');
        $this->assertDatabaseEmpty('loan_applications');

        $this->from($upload)->post($upload, [...$balance, 'shares' => '50000', 'evidence_reference' => 'Statement 02'])->assertRedirect($upload);
        $this->get($upload)->assertOk()->assertSee('Statement 02')->assertSee('Statement 01');
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
        $this->assertDatabaseHas('members', ['id' => $member, 'status' => 'inactive']);
        $this->assertNull($officer->fresh()->board_role);
        $this->assertDatabaseHas('board_role_changes', ['role' => 'chairperson', 'previous_user_id' => $officer->id, 'next_user_id' => null]);
        $this->actingAs($officer->fresh())->get('/member/account')->assertOk()->assertSee('Finalized')->assertDontSee('Request membership exit');
    }

    private function member(?int $legacyId = null): int
    {
        return DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Mushi', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active', 'legacy_member_id' => $legacyId,
        ]);
    }
}
