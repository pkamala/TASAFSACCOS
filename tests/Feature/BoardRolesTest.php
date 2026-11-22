<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BoardRolesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_administrator_can_transfer_a_role_between_existing_member_accounts(): void
    {
        $previous = $this->member('Outgoing', '10074', 'chairperson');
        $next = $this->member('Incoming', '10415');
        $secretary = $this->member('Secretary', '10164', 'secretary');
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $path = '/staff/board-roles';
        $payload = [
            'operation' => 'assign', 'current_holder' => $previous->id, 'registration_no' => '10415', 'reason' => 'AGM appointment',
        ];

        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs($secretary)->patch("$path/chairperson", $payload)->assertForbidden();
        $this->actingAs($admin)->get($path)->assertOk()->assertSee('Outgoing')->assertSee('10074');
        $this->from($path)->patch("$path/chairperson", [...$payload, 'registration_no' => '10164'])->assertSessionHasErrors('registration_no');
        $this->assertDatabaseEmpty('board_role_changes');

        $this->patch("$path/chairperson", $payload)->assertRedirect($path);
        $this->assertNull($previous->fresh()->board_role);
        $this->assertSame('chairperson', $next->fresh()->board_role);
        $this->assertDatabaseHas('board_role_changes', [
            'role' => 'chairperson', 'previous_user_id' => $previous->id, 'next_user_id' => $next->id,
            'changed_by' => $admin->id, 'reason' => 'AGM appointment',
        ]);
        $this->get($path)->assertOk()->assertSee('AGM appointment')->assertSee('Incoming');
        $this->from($path)->patch("$path/chairperson", $payload)->assertSessionHasErrors('current_holder');

        $this->actingAs($previous->fresh())->get('/member/account')->assertOk()->assertSee('10074');
        $this->get('/staff/loan-applications')->assertForbidden();
        $this->actingAs($next->fresh())->get('/staff/loan-applications')->assertOk();

        $this->actingAs($admin)->patch("$path/chairperson", [
            'operation' => 'vacate', 'current_holder' => $next->id, 'reason' => 'Term ended',
        ])->assertRedirect($path);
        $this->assertNull($next->fresh()->board_role);
        $this->assertDatabaseCount('board_role_changes', 2);
    }

    private function member(string $name, string $number, ?string $role = null): User
    {
        $email = strtolower($name).'@example.com';
        $id = DB::table('members')->insertGetId([
            'first_name' => $name, 'last_name' => 'Member', 'registration_no' => $number,
            'email' => $email, 'status' => 'active',
        ]);

        return User::factory()->create(['name' => $name, 'email' => $email, 'member_id' => $id, 'board_role' => $role]);
    }
}
