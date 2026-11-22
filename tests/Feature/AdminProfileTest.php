<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_admins_can_view_or_edit_their_own_profile(): void
    {
        $this->get('/staff/profile')->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->patch('/staff/profile', ['name' => 'Changed'])->assertForbidden();

        $admin = User::factory()->create(['is_staff_admin' => true]);
        $anotherAdmin = User::factory()->create(['is_staff_admin' => true]);
        $this->actingAs($admin)->get('/staff/profile')->assertOk()->assertSee($admin->email);
        $this->patch('/staff/profile', ['name' => 'Updated admin', 'user_id' => $anotherAdmin->id])
            ->assertRedirect('/staff/profile');
        $this->assertSame('Updated admin', $admin->fresh()->name);
        $this->assertSame($anotherAdmin->name, $anotherAdmin->fresh()->name);
        $this->assertDatabaseHas('admin_profile_audits', ['user_id' => $admin->id]);
    }

    public function test_password_rotation_requires_the_current_password_and_keeps_secrets_out_of_the_audit(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $payload = [
            'name' => $admin->name,
            'password' => 'New-example-password-42',
            'password_confirmation' => 'New-example-password-42',
        ];

        $this->actingAs($admin)->patch('/staff/profile', [...$payload, 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->assertDatabaseEmpty('admin_profile_audits');

        $this->patch('/staff/profile', [...$payload, 'current_password' => 'password'])
            ->assertRedirect('/staff/profile');
        $this->get('/staff/profile')->assertOk();
        $this->assertTrue(Hash::check($payload['password'], $admin->fresh()->getAuthPassword()));

        $audit = DB::table('admin_profile_audits')->where('user_id', $admin->id)->sole();
        $this->assertSame(['password_changed' => true], json_decode($audit->changes, true, flags: JSON_THROW_ON_ERROR));
    }
}
