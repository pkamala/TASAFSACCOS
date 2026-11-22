<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class StaffPasswordSetupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_board_setup_link_sets_a_password_once(): void
    {
        $officer = User::factory()->create(['board_role' => 'chairperson']);
        $token = Password::broker()->createToken($officer);
        $path = '/staff/password-setup';
        $url = route('staff.password-setup', ['email' => $officer->email, 'token' => $token]);
        $password = 'new-example-password';
        $data = ['email' => $officer->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password];

        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('Set staff password');
        $this->post($path, $data)->assertRedirect('/staff/login');
        $this->assertTrue(Hash::check($password, $officer->fresh()->getAuthPassword()));
        $this->from($url)->post($path, $data)->assertSessionHasErrors('email');
        $this->post('/staff/login', ['email' => $officer->email, 'password' => $password])
            ->assertRedirect('/staff/loan-applications');
    }

    public function test_provisioning_with_a_setup_link_creates_the_requested_role_and_a_token(): void
    {
        DB::table('members')->insert([
            'first_name' => 'Example', 'last_name' => 'Secretary', 'registration_no' => '10164',
            'email' => 'secretary@example.com', 'status' => 'active',
        ]);
        $this->artisan('staff:create-admin', [
            'name' => 'Example Secretary',
            'email' => 'secretary@example.com',
            '--role' => 'secretary',
            '--setup-link' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'secretary@example.com', 'board_role' => 'secretary', 'is_staff_admin' => false]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'secretary@example.com']);
    }

    public function test_first_time_setup_is_local_and_preserves_the_linked_board_role(): void
    {
        $this->app->instance('env', 'local');
        config(['auth.local_member_setup' => true]);

        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Ngoma', 'registration_no' => '10164',
            'email' => 'board@example.com', 'status' => 'active',
        ]);
        $officer = User::factory()->create([
            'email' => 'board@example.com', 'board_role' => 'secretary', 'local_setup_pending' => true, 'member_id' => $member,
        ]);
        $admin = User::factory()->create(['is_staff_admin' => true, 'local_setup_pending' => true]);
        $path = '/staff/first-time-setup';
        $password = 'new-local-board-password';
        $csrf = str_repeat('t', 40);
        $this->withSession(['_token' => $csrf]);
        $payload = ['_token' => $csrf, 'email' => $officer->email, 'registration_no' => '10164', 'password' => $password, 'password_confirmation' => $password];

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('http://localhost/staff/login')
            ->assertOk()->assertSee('Create your member account');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.42'])->post('http://localhost'.$path, $payload)
            ->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('http://localhost'.$path, ['X-Forwarded-For' => '192.0.2.42'])
            ->assertNotFound();

        $this->from('http://localhost'.$path)->post('http://localhost'.$path, [...$payload, 'email' => $admin->email])
            ->assertSessionHasErrors('email');
        $this->post('http://localhost'.$path, $payload)->assertRedirect('/staff/login');
        $this->assertFalse($officer->fresh()->local_setup_pending);
        $this->assertSame('secretary', $officer->fresh()->board_role);
        $this->assertTrue(Hash::check($password, $officer->fresh()->getAuthPassword()));
        $this->from('http://localhost'.$path)->post('http://localhost'.$path, $payload)->assertSessionHasErrors('email');
    }
}
