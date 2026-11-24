<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LoanApplicationTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith([null, 'committee'])]
    #[TestWith(['committee', 'board'])]
    #[TestWith(['board', 'board'])]
    public function test_intake_and_recommendation_route_loans_to_the_eligible_reviewer(?string $borrowerRole, string $lane): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Mushi', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active', 'joining_date' => '2026-08-22',
        ]);
        $inactive = DB::table('members')->insertGetId([
            'first_name' => 'Ali', 'last_name' => 'Mushi', 'registration_no' => '10339',
            'email' => 'ali@example.com', 'status' => 'inactive',
        ]);
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $board = User::factory()->create(['board_role' => 'chairperson']);
        $staff = DB::table('members')->insertGetId([
            'first_name' => 'Loan', 'last_name' => 'Clerk', 'registration_no' => 'STAFF-1', 'email' => 'intake@example.com', 'status' => 'active',
        ]);
        $committeeMember = DB::table('members')->insertGetId([
            'first_name' => 'Committee', 'last_name' => 'Officer', 'registration_no' => 'COMMITTEE-1', 'email' => 'committee@example.com', 'status' => 'active',
        ]);
        $creator = User::factory()->create(['member_id' => $staff, 'loan_workflow_role' => 'staff']);
        $committee = User::factory()->create(['member_id' => $committeeMember, 'loan_workflow_role' => 'committee']);
        if ($borrowerRole !== null) {
            User::factory()->create(['member_id' => $member, 'loan_workflow_role' => $borrowerRole]);
        }
        $sponsorNumbers = ['SPONSOR-1', 'SPONSOR-2', 'SPONSOR-3'];
        $sponsors = [];
        foreach ($sponsorNumbers as $number) {
            $id = DB::table('members')->insertGetId([
                'first_name' => 'Sponsor', 'last_name' => 'Member', 'registration_no' => $number,
                'email' => "$number@example.com", 'status' => 'active',
            ]);
            $sponsors[] = User::factory()->create(['member_id' => $id]);
        }
        $reviewer = $lane === 'committee' ? $committee : $board;
        $otherReviewer = $lane === 'committee' ? $board : $committee;
        $revision = DB::table('loan_pricing_revisions')->insertGetId([
            'annual_basis_points' => 1000, 'interest_method' => 'reducing', 'insurance_basis_points' => 200,
            'processing_fee_minor' => 3000000, 'other_charges_minor' => 0,
            'changed_by' => $admin->id, 'reason' => 'Board approved pricing', 'changed_at' => now(),
        ]);
        DB::table('loan_eligibility_revisions')->insert([
            'min_savings_minor' => 1000, 'min_shares_minor' => 2000, 'changed_by' => $admin->id,
            'reason' => 'Board requirements', 'changed_at' => now(),
        ]);
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-11-22', 'savings_minor' => 35000000, 'shares_minor' => 50000000,
            'evidence_reference' => 'Reconciled statement', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $path = "/staff/members/$member/loan-applications";

        $this->get("$path/new")->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
        $this->actingAs($creator)->get('/staff/loan-intake')->assertOk()->assertSee('10415')->assertDontSee('10339');
        $this->get("$path/new")->assertOk()->assertSee('New loan application')->assertSee('Choose sponsor 1')->assertSee('Choose sponsor 2');
        $this->get("/staff/members/$inactive/loan-applications/new")->assertForbidden();

        $payload = [
            'submission_id' => (string) Str::uuid(),
            'principal' => '1000000',
            'sponsors' => array_slice($sponsorNumbers, 0, 2),
            'pricing_revision_id' => $revision, 'loan_category_id' => DB::table('loan_categories')->where('name', 'Staff loan')->value('id'), 'term_months' => '3', 'first_due_date' => '2027-01-31',
        ];
        $this->from("$path/new")->post($path, [...$payload, 'annual_rate' => '1'])->assertSessionHasErrors('annual_rate');
        $this->from("$path/new")->post($path, [...$payload, 'pricing_revision_id' => $revision + 1])->assertSessionHasErrors('pricing_revision_id');
        $this->post($path, [...$payload, 'principal' => '1000000.01'])->assertSessionHasErrors('principal');
        $this->post($path, [...$payload, 'sponsors' => ['SPONSOR-1']])->assertSessionHasErrors('sponsors');
        $this->post($path, [...$payload, 'sponsors' => ['SPONSOR-1', 'sponsor-1']])->assertSessionHasErrors('sponsors');
        $this->post($path, [...$payload, 'sponsors' => ['SPONSOR-1', '10415']])->assertSessionHasErrors('sponsors');
        $this->assertDatabaseEmpty('loan_applications');
        $this->post($path, $payload)->assertRedirect();
        $application = DB::table('loan_applications')->sole();
        $this->assertSame($revision, $application->loan_pricing_revision_id);
        $this->assertSame(2000000, $application->insurance_minor);
        $this->assertSame(1000, $application->annual_basis_points);
        $url = "$path/$application->id";
        $this->post($path, $payload)->assertRedirect($url);
        $this->assertDatabaseCount('loan_applications', 1);
        $this->assertDatabaseCount('loan_application_installments', 3);
        $this->assertDatabaseHas('loan_application_installments', [
            'loan_application_id' => $application->id, 'number' => 1, 'opening_minor' => 105000000,
        ]);
        $this->assertDatabaseHas('loan_application_installments', [
            'loan_application_id' => $application->id, 'number' => 2, 'due_date' => '2027-02-28',
        ]);
        $this->assertDatabaseHas('loan_application_installments', [
            'loan_application_id' => $application->id, 'number' => 3, 'closing_minor' => 0,
        ]);
        $this->assertSame(105000000, (int) DB::table('loan_application_installments')->sum('principal_minor'));
        $this->get($url)->assertOk()->assertSee('Proposed repayment schedule')->assertSee('Awaiting sponsor confirmations');
        $this->get("/staff/members/$inactive/loan-applications/$application->id")->assertNotFound();
        $this->post("$url/review", ['decision' => 'approved', 'reason' => 'Reviewed terms'])->assertForbidden();
        $recommendation = ['recommendation' => 'approve', 'recommendation_note' => 'Reviewed payment capacity'];
        $decision = ['decision' => 'approved', 'reason' => 'Reviewed terms'];
        $this->post("$url/recommend", $recommendation)->assertForbidden();
        $this->actingAs($reviewer)->post("$url/review", $decision)->assertForbidden();

        $first = DB::table('loan_sponsorships')->where('member_id', $sponsors[0]->member_id)->sole();
        $second = DB::table('loan_sponsorships')->where('member_id', $sponsors[1]->member_id)->sole();
        $confirmation = fn (int $id): string => "/member/loan-sponsorships/$id/confirm";

        $this->actingAs($admin)->from($url)->post("$url/recommend", $recommendation)->assertSessionHasErrors('sponsors');
        $this->get('/staff/tasks')->assertOk()->assertDontSee('Recommend and forward loan application');
        $this->post($confirmation($first->id))->assertForbidden();
        $this->actingAs($sponsors[2])->get('/member/account')->assertOk()->assertDontSee('Confirm sponsorship');
        $this->post($confirmation($first->id))->assertNotFound();
        $this->actingAs($sponsors[0])->get('/member/account')->assertOk()
            ->assertSee('1,050,000.00')->assertSee($confirmation($first->id))->assertDontSee($confirmation($second->id));
        $this->post($confirmation($first->id))->assertRedirect('/member/account');
        $this->post($confirmation($first->id))->assertRedirect('/member/account');
        $this->assertDatabaseHas('loan_sponsorships', ['id' => $first->id, 'confirmed_by' => $sponsors[0]->id]);

        $this->actingAs($admin)->from($url)->post("$url/recommend", $recommendation)->assertSessionHasErrors('sponsors');
        $this->patch("$url/sponsors", ['sponsors' => ['SPONSOR-1', 'SPONSOR-3']])->assertRedirect($url);
        $this->assertDatabaseHas('loan_sponsorships', ['id' => $first->id, 'confirmed_by' => $sponsors[0]->id]);
        $this->actingAs($sponsors[1])->post($confirmation($second->id))->assertNotFound();
        $replacement = DB::table('loan_sponsorships')->where('member_id', $sponsors[2]->member_id)->sole();
        $this->actingAs($sponsors[2])->post($confirmation($replacement->id))->assertRedirect('/member/account');
        $this->actingAs($admin)->get('/staff/tasks')->assertOk()->assertSee('Recommend and forward loan application');
        $this->post("$url/recommend", $recommendation)->assertRedirect($url);
        $this->assertDatabaseHas('loan_applications', [
            'id' => $application->id, 'approval_lane' => $lane, 'recommended_by' => $admin->id,
            'recommendation_note' => 'Reviewed payment capacity',
        ]);
        $this->get('/staff/loan-register?status=submitted')->assertOk()->assertSee('10415')->assertSee('Print register');
        $this->get('/staff/loan-register?status=approved')->assertOk()->assertDontSee('10415');
        $this->actingAs($otherReviewer)->post("$url/review", $decision)->assertForbidden();

        $this->actingAs($reviewer)->get('/staff/loan-applications')->assertOk()->assertSee('10415')->assertSee($url);
        $this->get('/staff/tasks')->assertOk()->assertSee('Review loan application');
        if ($lane === 'board') {
            $this->from($url)->post("$url/review", $decision)->assertSessionHasErrors('minutes');
            $decision['minutes'] = UploadedFile::fake()->createWithContent('minutes.pdf', "%PDF-1.4\n%%EOF");
        }
        DB::table('member_verified_balances')->where('member_id', $member)->update(['savings_minor' => 34999999]);
        $this->from($url)->post("$url/review", $decision)->assertSessionHasErrors('principal');
        DB::table('member_verified_balances')->where('member_id', $member)->update(['savings_minor' => 35000000]);
        if ($lane === 'board') {
            $decision['minutes'] = UploadedFile::fake()->createWithContent('minutes.pdf', "%PDF-1.4\n%%EOF");
        }
        $this->post("$url/review", $decision)->assertRedirect($url);
        if ($lane === 'board') {
            $this->get("$url/minutes")->assertOk()->assertHeader('content-type', 'application/pdf');
        }
        $this->assertDatabaseHas('loan_applications', ['id' => $application->id, 'status' => 'approved']);
        $this->assertDatabaseHas('loan_application_decisions', [
            'loan_application_id' => $application->id, 'reviewed_by' => $reviewer->id, 'reason' => 'Reviewed terms',
        ]);
        $this->actingAs($admin)->get('/staff/loan-register?status=submitted')->assertOk()->assertDontSee('10415');
        $this->get('/staff/loan-register?status=approved')->assertOk()->assertSee('10415');
        $this->get($url)->assertOk()->assertSee('Approved by')->assertSee($reviewer->name);
        $this->actingAs($reviewer)->from($url)->post("$url/review", ['decision' => 'declined', 'reason' => 'Another decision'])
            ->assertSessionHasErrors('decision');
        $this->assertDatabaseCount('loan_application_decisions', 1);
        $this->get('/staff/loan-applications')->assertOk()->assertSee('No applications awaiting review.');
        $this->actingAs($creator)->from("$path/new")
            ->post($path, [...$payload, 'submission_id' => (string) Str::uuid(), 'principal' => '100'])
            ->assertSessionHasErrors('principal');
    }

    public function test_sponsor_picker_only_returns_eligible_members_to_staff(): void
    {
        $members = [];
        foreach (['BORROWER', 'ELIGIBLE', 'INACTIVE', 'NO-ACCOUNT', 'ADMIN'] as $number) {
            $members[$number] = DB::table('members')->insertGetId([
                'first_name' => 'Maria', 'middle_name' => 'A.', 'last_name' => 'Moshi',
                'registration_no' => $number, 'email' => "$number@example.com",
                'status' => $number === 'INACTIVE' ? 'inactive' : 'active',
            ]);
        }
        foreach (['BORROWER', 'INACTIVE', 'ADMIN'] as $number) {
            User::factory()->create(['member_id' => $members[$number], 'is_staff_admin' => $number === 'ADMIN']);
        }
        $intake = User::factory()->create(['member_id' => $members['ELIGIBLE'], 'loan_workflow_role' => 'staff']);
        $url = '/staff/members/'.$members['BORROWER'].'/loan-sponsors';

        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        $this->actingAs($intake)->getJson($url.'?q=maria%20moshi')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([[
                'first_name' => 'Maria', 'middle_name' => 'A.', 'last_name' => 'Moshi', 'registration_no' => 'ELIGIBLE',
            ]]);
        $this->getJson($url.'?q=eligible')->assertOk()->assertJsonCount(1);
        $this->getJson($url.'?q=%25')->assertOk()->assertExactJson([]);
    }

    public function test_only_admins_can_add_categories_and_names_are_unique_regardless_of_case(): void
    {
        $path = '/staff/loan-categories';
        $this->actingAs(User::factory()->create(['board_role' => 'chairperson']))
            ->post($path, ['name' => 'Education loan'])->assertForbidden();

        $admin = User::factory()->create(['is_staff_admin' => true]);
        $this->actingAs($admin)->get($path)->assertOk()->assertSee('Staff loan')->assertSee('Emergency loan');
        $this->post($path, ['name' => 'Education loan'])->assertRedirect($path);
        $this->assertDatabaseHas('loan_categories', ['name' => 'Education loan', 'created_by' => $admin->id]);
        $this->from($path)->post($path, ['name' => 'education loan'])->assertSessionHasErrors('name');
    }
}
