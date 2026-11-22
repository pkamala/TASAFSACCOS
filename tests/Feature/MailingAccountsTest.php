<?php

namespace Tests\Feature;

use App\Models\User;
use App\StatementMailer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailingAccountsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_create_and_select_an_encrypted_smtp_account_for_statements(): void
    {
        $path = '/staff/mailing-accounts';
        $payload = [
            'label' => 'Statements', 'from_address' => 'statements@example.com', 'from_name' => 'TASAF SACCOS',
            'host' => 'smtp.example.com', 'port' => 587, 'security' => 'starttls',
            'username' => 'statements@example.com', 'password' => 'sample-smtp-app-password',
        ];
        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create(['board_role' => 'secretary']))->post($path, $payload)->assertForbidden();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $this->actingAs($admin)->from($path)->post($path, [...$payload, 'host' => 'smtp://wrong.example.com'])
            ->assertSessionHasErrors('host')->assertSessionMissing('_old_input.password');
        $this->post($path, $payload)->assertRedirect($path);
        $account = DB::table('mailing_accounts')->sole();
        $this->assertNotSame($payload['password'], $account->encrypted_password);
        $this->assertSame($payload['password'], Crypt::decryptString($account->encrypted_password));
        $this->get($path)->assertOk()->assertSee('Use for outgoing mail')->assertDontSee($payload['password'])->assertDontSee($account->encrypted_password);

        config(['mail.default' => 'log']);
        $sender = app(StatementMailer::class);
        $this->assertFalse($sender->configured($sender->selectedAccountId()));
        $this->patch("$path/selection", ['account' => $account->id])->assertRedirect($path);
        $this->assertDatabaseHas('mailing_account_selections', ['mailing_account_id' => $account->id, 'changed_by' => $admin->id]);
        $this->assertTrue($sender->configured($sender->selectedAccountId()));
        $transport = $sender->forDelivery($sender->selectedAccountId())->getSymfonyTransport();
        $this->assertSame($payload['username'], $transport->getUsername());
        $this->assertSame($payload['host'], $transport->getStream()->getHost());

        $this->patch("$path/selection", ['account' => ''])->assertRedirect($path);
        $this->assertNull($sender->selectedAccountId());
        $this->assertDatabaseCount('mailing_account_selections', 2);

        config(['statements.monthly_email_enabled' => false]);
        $this->from($path)->patch("$path/automatic", ['enabled' => 1])->assertSessionHasErrors('enabled');
        $this->patch("$path/selection", ['account' => $account->id])->assertRedirect();
        Mail::fake();
        $this->patch("$path/automatic", ['enabled' => 1])->assertRedirect("$path#automatic-delivery");
        $this->assertTrue($sender->shouldSendAutomatically());
        $this->assertDatabaseHas('statement_auto_send_changes', ['enabled' => true, 'changed_by' => $admin->id]);
        $this->get('/staff/statement-review')->assertOk()->assertSee('Scheduled delivery is enabled.');
        $this->patch("$path/automatic", ['enabled' => 0])->assertRedirect();
        config(['statements.monthly_email_enabled' => true]);
        $this->assertFalse($sender->shouldSendAutomatically());
        $this->artisan('statements:send', ['--send' => true, '--scheduled' => true])
            ->expectsOutput('Automatic statement delivery is paused.')->assertSuccessful();
        Mail::assertNothingOutgoing();
    }

    public function test_incoming_settings_store_an_encrypted_password_and_require_it_for_a_connection_change(): void
    {
        $path = '/staff/mailing-accounts';
        $payload = [
            'protocol' => 'imap', 'host' => 'imap.example.com', 'port' => '993',
            'security' => 'tls', 'username' => 'inbox@example.com', 'password' => 'sample-incoming-password',
        ];
        $this->actingAs(User::factory()->create())->patch("$path/incoming", $payload)->assertForbidden();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $this->actingAs($admin)->patch("$path/incoming", $payload)->assertRedirect("$path#incoming-mail");
        $first = DB::table('incoming_mail_settings')->sole();
        $this->assertNotSame($payload['password'], $first->encrypted_password);
        $this->assertSame($payload['password'], Crypt::decryptString($first->encrypted_password));
        $this->get($path)->assertOk()->assertSee('imap.example.com')->assertDontSee($payload['password'])->assertDontSee($first->encrypted_password);
        $this->patch("$path/incoming", [...$payload, 'password' => ''])->assertRedirect();
        $this->assertDatabaseHas('incoming_mail_settings', ['encrypted_password' => $first->encrypted_password]);
        $replacement = [...$payload, 'protocol' => 'pop3', 'host' => 'pop.example.com', 'port' => 995];
        $this->from($path)->patch("$path/incoming", [...$replacement, 'password' => ''])->assertSessionHasErrors('password');
        $this->patch("$path/incoming", $replacement)->assertRedirect();
        $this->assertDatabaseHas('incoming_mail_settings', ['protocol' => 'pop3', 'port' => 995, 'changed_by' => $admin->id]);
        $this->assertDatabaseCount('incoming_mail_settings', 1);
    }
}
