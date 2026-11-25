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
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 25));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'registration_no' => '10415',
            'first_name' => 'Asha',
            'last_name' => 'Mushi',
            'email' => 'member@example.com',
            'joining_date' => '2026-08-31',
            'status' => 'inactive',
        ]);

        $this->post('/staff/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/staff/members');
        $this->get('/staff/members?q=10415')->assertOk()->assertSee('Asha Mushi');
        $this->get('/staff/members?q=10415', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('data-live-table-results', false)->assertSee('Asha Mushi')->assertSee('Membership (months)')->assertSee('<td>2</td>', false)
            ->assertDontSee('<!doctype html>', false);
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

    public function test_members_page_size_keeps_the_search_and_accepts_only_supported_sizes(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        DB::table('members')->insert(array_map(fn (int $sequence): array => [
            'registration_no' => "TEST-$sequence", 'first_name' => 'Pagination', 'last_name' => 'Member',
            'email' => "page-$sequence@example.com", 'status' => 'active',
        ], range(1, 51)));
        DB::table('members')->insert([
            'registration_no' => 'OTHER-1', 'first_name' => 'Other', 'last_name' => 'Member',
            'email' => 'other@example.com', 'status' => 'active',
        ]);
        $path = '/staff/members?q=Pagination';
        $this->actingAs($admin)->get($path)->assertOk()
            ->assertViewHas('members', fn ($page): bool => $page->count() === 10)
            ->assertSee('Showing 1–10 of 51 records');
        foreach ([10 => 10, 25 => 25, 50 => 50, 100 => 51, 999 => 10] as $size => $count) {
            $this->get("$path&per_page=$size")->assertOk()
                ->assertViewHas('members', fn ($page): bool => $page->count() === $count);
        }
        $this->get("$path&per_page=10&page=2")->assertOk()
            ->assertViewHas('members', fn ($page): bool => $page->currentPage() === 2 && $page->count() === 10)
            ->assertSee('Showing 11–20 of 51 records')
            ->assertSee('name="q" value="Pagination"', false)
            ->assertSee('per_page=10&amp;page=3', false);
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->get('/staff/members?q=OTHER-1', $ajax)->assertOk()->assertSee('Showing 1–1 of 1 record');
        $this->get('/staff/members?q=UNKNOWN', $ajax)->assertOk()->assertSee('0 records');
    }
}
