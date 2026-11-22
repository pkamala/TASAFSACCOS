<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberAccountTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_member_can_create_an_account_on_the_pc_and_only_see_their_membership(): void
    {
        $this->app->instance('env', 'local');
        config(['auth.local_member_setup' => true]);
        $member = $this->member('Asha', '10074', 'member@example.com');
        $other = $this->member('Another', '10164', 'other@example.com');
        $csrf = str_repeat('t', 40);
        $this->withSession(['_token' => $csrf]);
        $path = 'http://localhost/staff/first-time-setup';
        $data = [
            '_token' => $csrf, 'email' => 'member@example.com', 'registration_no' => '10074',
            'password' => 'normal-member-password', 'password_confirmation' => 'normal-member-password',
        ];

        $this->from($path)->post($path, [...$data, 'registration_no' => '10164'])->assertSessionHasErrors('email');
        $this->assertDatabaseEmpty('users');
        $this->post($path, $data)->assertRedirect('/staff/login');
        $this->assertDatabaseHas('users', ['email' => $data['email'], 'member_id' => $member, 'board_role' => null, 'is_staff_admin' => false]);
        $this->post('http://localhost/staff/login', ['_token' => $csrf, 'email' => $data['email'], 'password' => $data['password']])
            ->assertRedirect('/member/account');
        $this->get('/member/account')->assertOk()->assertSee('10074')->assertDontSee('10164');
        $this->get("/staff/members/$other")->assertForbidden();
        $this->get('/staff/loan-applications')->assertForbidden();
    }

    public function test_only_the_opposite_board_role_can_decide_each_board_members_loan(): void
    {
        $chairMember = $this->member('Abbas', '10074', 'chair@example.com');
        $secretaryMember = $this->member('Zacharia', '10164', 'secretary@example.com');
        $chair = User::factory()->create(['member_id' => $chairMember, 'board_role' => 'chairperson']);
        $secretary = User::factory()->create(['member_id' => $secretaryMember, 'board_role' => 'secretary']);
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $chairLoan = $this->application($chairMember, $admin->id);
        $secretaryLoan = $this->application($secretaryMember, $admin->id);
        $loan = fn (int $member, int $id): string => "/staff/members/$member/loan-applications/$id";
        $chairPath = $loan($chairMember, $chairLoan);
        $secretaryPath = $loan($secretaryMember, $secretaryLoan);

        $this->actingAs($chair)->get($chairPath)->assertOk()->assertDontSee('Record decision');
        $this->post("$chairPath/review", [])->assertForbidden();
        $this->actingAs($secretary)->post("$secretaryPath/review", [])->assertForbidden();
        $this->actingAs($admin)->post("$chairPath/review", [])->assertForbidden();
        $this->assertDatabaseEmpty('loan_application_decisions');

        $decision = static fn (): array => [
            'decision' => 'approved', 'reason' => 'Board approved',
            'minutes' => UploadedFile::fake()->createWithContent('minutes.pdf', "%PDF-1.4\n%%EOF"),
        ];
        $this->actingAs($secretary)->post("$chairPath/review", $decision())->assertRedirect($chairPath);
        $this->actingAs($chair)->post("$secretaryPath/review", $decision())->assertRedirect($secretaryPath);
        $this->assertDatabaseHas('loan_application_decisions', ['loan_application_id' => $chairLoan, 'reviewed_by' => $secretary->id]);
        $this->assertDatabaseHas('loan_application_decisions', ['loan_application_id' => $secretaryLoan, 'reviewed_by' => $chair->id]);
    }

    private function member(string $name, string $number, string $email): int
    {
        return DB::table('members')->insertGetId([
            'first_name' => $name, 'last_name' => 'Member', 'registration_no' => $number,
            'email' => $email, 'status' => 'active',
        ]);
    }

    private function application(int $member, int $actor): int
    {
        return DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(),
            'member_id' => $member, 'created_by' => $actor, 'principal_minor' => 100000000,
            'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 1000, 'term_months' => 12, 'interest_method' => 'reducing',
            'first_due_date' => '2027-01-31', 'status' => 'submitted', 'created_at' => now(),
        ]);
    }
}
