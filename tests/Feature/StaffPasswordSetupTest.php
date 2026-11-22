<?php

namespace Tests\Feature;

use App\Mail\MemberLoginLinkMail;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('Set account password');
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

    public function test_admin_issues_single_use_member_setup_and_reset_links_without_self_registration(): void
    {
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10164',
            'email' => 'board@example.com', 'status' => 'active',
        ]);
        $profile = "/staff/members/$member";
        $this->get('/staff/login')->assertOk()->assertDontSee('Create your member account');
        $this->get('/staff/first-time-setup')->assertNotFound();
        $this->post('/staff/first-time-setup', ['registration_no' => '10164'])->assertNotFound();
        $this->actingAs(User::factory()->create())->post("$profile/login-link")->assertForbidden();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        config(['mail.default' => 'log']);
        $this->actingAs($admin)->from($profile)->post("$profile/login-link")->assertSessionHasErrors('login');
        $this->assertDatabaseMissing('users', ['member_id' => $member]);

        config(['mail.default' => 'smtp', 'app.url' => 'https://saccos.example.test']);
        Mail::fake();
        $this->post("$profile/login-link")->assertRedirect("$profile#member-login");
        Mail::assertSent(MemberLoginLinkMail::class, fn ($mail): bool => $mail->hasTo('board@example.com') && $mail->setup);
        $first = Mail::sent(MemberLoginLinkMail::class)->last()->url;
        $this->assertStringStartsWith('https://saccos.example.test/staff/password-setup?', $first);
        $this->get($profile)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Setup pending')->assertSee('Login email sent')->assertDontSee($first);
        $this->post("$profile/login-link")->assertRedirect();
        $latest = Mail::sent(MemberLoginLinkMail::class)->last()->url;
        $user = User::query()->where('member_id', $member)->sole();
        $this->assertTrue($user->local_setup_pending);
        $this->assertDatabaseCount('member_audits', 4);
        $this->assertStringNotContainsString('token=', DB::table('member_audits')->orderByDesc('id')->value('changes'));

        $this->post('/staff/logout');
        $password = 'new-example-password';
        parse_str(parse_url($first, PHP_URL_QUERY), $expired);
        parse_str(parse_url($latest, PHP_URL_QUERY), $data);
        $credentials = ['password' => $password, 'password_confirmation' => $password];
        $this->from($latest)->post('/staff/password-setup', [...$expired, ...$credentials])->assertSessionHasErrors('email');
        $this->post('/staff/password-setup', [...$data, ...$credentials])->assertRedirect('/staff/login');
        $this->assertFalse($user->fresh()->local_setup_pending);
        $this->post('/staff/login', ['email' => $user->email, 'password' => $password])->assertRedirect('/member/account');

        $this->post('/staff/logout');
        $user->board_role = 'secretary';
        $user->save();
        $this->actingAs($admin)->post("$profile/login-link")->assertRedirect();
        $email = Mail::sent(MemberLoginLinkMail::class)->last();
        $this->assertFalse($email->setup);
        parse_str(parse_url($email->url, PHP_URL_QUERY), $reset);
        $this->post('/staff/logout');
        $newPassword = 'replacement-password';
        $this->post('/staff/password-setup', [...$reset, 'password' => $newPassword, 'password_confirmation' => $newPassword])->assertRedirect('/staff/login');
        $this->assertSame('secretary', $user->fresh()->board_role);
        $this->assertTrue(Hash::check($newPassword, $user->fresh()->getAuthPassword()));
    }

    public function test_failed_email_delivery_can_be_retried_without_changing_an_existing_password(): void
    {
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10164',
            'email' => 'member@example.com', 'status' => 'active',
        ]);
        $user = User::factory()->create(['member_id' => $member, 'email' => 'member@example.com']);
        $admin = User::factory()->create(['is_staff_admin' => true]);
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('mailer')->once()->andThrow(new \RuntimeException('SMTP unavailable'));

        $profile = "/staff/members/$member";
        $this->actingAs($admin)->post("$profile/login-link")->assertRedirect("$profile#member-login")
            ->assertSessionHasErrors('login');
        $this->assertSame($user->password, $user->fresh()->password);
        $this->get($profile)->assertOk()->assertSee('Delivery not confirmed')->assertDontSee('SMTP unavailable');
    }
}
