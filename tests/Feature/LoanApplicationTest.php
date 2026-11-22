<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoanApplicationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_can_submit_once_and_a_board_officer_can_review_the_application(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Mushi', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active',
        ]);
        $inactive = DB::table('members')->insertGetId([
            'first_name' => 'Ali', 'last_name' => 'Mushi', 'registration_no' => '10339',
            'email' => 'ali@example.com', 'status' => 'inactive',
        ]);
        $creator = User::factory()->create(['is_staff_admin' => true]);
        $reviewer = User::factory()->create(['board_role' => 'chairperson']);
        $revision = DB::table('loan_pricing_revisions')->insertGetId([
            'annual_basis_points' => 1000, 'interest_method' => 'reducing', 'insurance_basis_points' => 200,
            'processing_fee_minor' => 3000000, 'other_charges_minor' => 0,
            'changed_by' => $creator->id, 'reason' => 'Board approved pricing', 'changed_at' => now(),
        ]);
        DB::table('loan_eligibility_revisions')->insert([
            'min_savings_minor' => 1000, 'min_shares_minor' => 2000, 'changed_by' => $creator->id,
            'reason' => 'Board requirements', 'changed_at' => now(),
        ]);
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-11-22', 'savings_minor' => 1000, 'shares_minor' => 2000,
            'evidence_reference' => 'Reconciled statement', 'recorded_by' => $creator->id, 'recorded_at' => now(),
        ]);
        $path = "/staff/members/$member/loan-applications";

        $this->get("$path/new")->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
        $this->actingAs($creator)->get("$path/new")->assertOk()->assertSee('New loan application');
        $this->get("/staff/members/$inactive/loan-applications/new")->assertForbidden();

        $payload = [
            'submission_id' => (string) Str::uuid(),
            'principal' => '1000000',
            'pricing_revision_id' => $revision, 'loan_category_id' => DB::table('loan_categories')->where('name', 'Staff loan')->value('id'), 'term_months' => '3', 'first_due_date' => '2027-01-31',
        ];
        $this->from("$path/new")->post($path, [...$payload, 'annual_rate' => '1'])->assertSessionHasErrors('annual_rate');
        $this->from("$path/new")->post($path, [...$payload, 'pricing_revision_id' => $revision + 1])->assertSessionHasErrors('pricing_revision_id');
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
        $this->get($url)->assertOk()->assertSee('Proposed repayment schedule')->assertSee('Awaiting an eligible Chairperson');
        $this->get("/staff/members/$inactive/loan-applications/$application->id")->assertNotFound();
        $this->post("$url/review", ['decision' => 'approved', 'reason' => 'Reviewed terms'])->assertForbidden();
        $this->get('/staff/loan-register?status=submitted')->assertOk()->assertSee('10415')->assertSee('Print register');
        $this->get('/staff/loan-register?status=approved')->assertOk()->assertDontSee('10415');

        $this->actingAs($reviewer)->get('/staff/loan-applications')->assertOk()->assertSee('10415')->assertSee($url);
        $this->from($url)->post("$url/review", ['decision' => 'approved', 'reason' => 'Reviewed terms'])->assertSessionHasErrors('minutes');
        $minutes = static fn () => UploadedFile::fake()->createWithContent('minutes.pdf', "%PDF-1.4\n%%EOF");
        $this->post("$url/review", ['decision' => 'approved', 'reason' => 'Reviewed terms', 'minutes' => $minutes()])
            ->assertRedirect($url);
        $this->get("$url/minutes")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertDatabaseHas('loan_applications', ['id' => $application->id, 'status' => 'approved']);
        $this->assertDatabaseHas('loan_application_decisions', [
            'loan_application_id' => $application->id, 'reviewed_by' => $reviewer->id, 'reason' => 'Reviewed terms',
        ]);
        $this->get('/staff/loan-register?status=submitted')->assertOk()->assertDontSee('10415');
        $this->get('/staff/loan-register?status=approved')->assertOk()->assertSee('10415');
        $this->get($url)->assertOk()->assertSee('Approved by')->assertSee($reviewer->name);
        $this->from($url)->post("$url/review", ['decision' => 'declined', 'reason' => 'Another decision', 'minutes' => $minutes()])
            ->assertSessionHasErrors('decision');
        $this->assertDatabaseCount('loan_application_decisions', 1);
        $this->get('/staff/loan-applications')->assertOk()->assertSee('No applications awaiting review.');
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
