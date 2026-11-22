<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaffMembersTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_and_non_staff_cannot_access_member_profiles(): void
    {
        $this->get('/staff/members')->assertRedirect('/staff/login');

        $user = User::factory()->create();
        $this->from('/staff/login')->post('/staff/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($user)->get('/staff/members')->assertForbidden();
        $this->patch('/staff/members/1', [])->assertForbidden();
    }

    public function test_staff_can_search_and_edit_a_profile_with_an_audited_change(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'registration_no' => '10415',
            'first_name' => 'Asha',
            'last_name' => 'Mushi',
            'email' => 'member@example.com',
            'status' => 'inactive',
        ]);

        $this->post('/staff/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/staff/members');
        $this->get('/staff/members?q=10415')->assertOk()->assertSee('Asha Mushi');
        $this->get('/staff/members?q=99999')->assertOk()->assertDontSee('Asha Mushi');

        $details = [
            'first_name' => 'Asha',
            'middle_name' => null,
            'last_name' => 'Mwinyi',
            'phone' => '+255712345678',
            'joining_date' => '2023-01-01',
        ];
        $profile = "/staff/members/$member";
        $this->patch($profile, [...$details, 'phone' => 'invalid'])->assertSessionHasErrors('phone');
        $this->assertDatabaseEmpty('member_audits');

        $this->patch($profile, $details)->assertRedirect($profile);
        $this->assertDatabaseHas('members', ['id' => $member, 'last_name' => 'Mwinyi', 'registration_no' => '10415']);
        $this->assertDatabaseHas('member_audits', ['member_id' => $member, 'actor_id' => $admin->id]);
        $this->get($profile)->assertOk()->assertSee('Mushi → Mwinyi')->assertSee($admin->name);
        $this->patch($profile, $details)->assertRedirect($profile);
        $this->assertDatabaseCount('member_audits', 1);
        $this->post('/staff/logout')->assertRedirect('/staff/login');
        $this->assertGuest();
    }
}
