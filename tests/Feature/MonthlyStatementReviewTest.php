<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MonthlyStatementReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_preview_then_queue_reviewed_statements_without_sending_email(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        Mail::fake();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'member@example.com', 'status' => 'active',
        ]);
        $path = '/staff/statement-review';
        $header = "registration_no,email,loan_balance,monthly_contribution,savings_balance\n";
        $data = "10415,member@example.com,125.50,100.00,500.25\n";
        $file = fn (string $rows): UploadedFile => UploadedFile::fake()->createWithContent('statements.csv', $header.$rows);
        $form = ['period' => '2026-10', 'review_reference' => 'Reviewed by finance'];

        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->post("$path/queue", [])->assertForbidden();
        $this->actingAs($admin)->from($path)->post("$path/queue", ['token' => str_repeat('x', 40)])->assertSessionHasErrors('statements');
        $this->from($path)->post("$path/preview", [...$form, 'statements' => $file(str_replace('member@example.com', 'wrong@example.com', $data))])
            ->assertSessionHasErrors('statements');

        $this->post("$path/preview", [...$form, 'statements' => $file($data)])->assertRedirect("$path?period=2026-10#preview");
        $this->get($path)->assertOk()->assertSee('1 ready to queue')->assertSee('member@example.com')->assertSee('500.25');
        $token = session('monthly_statement_review.token');
        $this->assertDatabaseEmpty('monthly_member_statements');

        DB::table('members')->where('id', $member)->update(['email' => 'changed@example.com']);
        $this->from($path)->post("$path/queue", ['token' => $token])->assertSessionHasErrors('statements');
        $this->assertDatabaseEmpty('monthly_member_statements');
        DB::table('members')->where('id', $member)->update(['email' => 'member@example.com']);
        $this->post("$path/preview", [...$form, 'statements' => $file($data)])->assertRedirect();
        $token = session('monthly_statement_review.token');
        $this->post("$path/queue", ['token' => $token])->assertRedirect("$path?period=2026-10");

        $this->assertDatabaseHas('monthly_member_statements', [
            'member_id' => $member, 'period' => '2026-10-01', 'recipient' => 'member@example.com',
            'loan_balance_minor' => 12550, 'contribution_minor' => 10000, 'savings_balance_minor' => 50025,
            'source_sha256' => hash('sha256', $header.$data), 'queued_by' => $admin->id, 'review_reference' => $form['review_reference'],
            'delivery_status' => 'pending', 'sent_at' => null,
        ]);
        $this->get($path)->assertOk()->assertSee('1 ready to send')->assertSee($form['review_reference']);
        $this->post("$path/preview", [...$form, 'statements' => $file($data)])->assertRedirect();
        $this->get($path)->assertOk()->assertSee('1 already recorded')->assertDontSee('Queue reviewed statements');
        $this->assertDatabaseCount('monthly_member_statements', 1);

        DB::table('members')->where('id', $member)->update(['email' => 'MEMBER@example.com']);
        $this->get($path)->assertOk()->assertSee('1 ready to send')->assertSee('0 held for email mismatch');

        DB::table('members')->where('id', $member)->update(['email' => 'changed@example.com']);
        $this->get($path)->assertOk()->assertSee('0 ready to send')->assertSee('1 held for email mismatch')
            ->assertSee('Held for email mismatch');
        $this->assertDatabaseHas('monthly_member_statements', ['member_id' => $member, 'delivery_status' => 'pending']);
        Mail::assertNothingOutgoing();
    }

    public function test_admin_can_preview_a_sample_email_without_using_members_or_the_delivery_transport(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(12, 0));
        Mail::fake();
        $path = '/staff/statement-review/sample?period=2026-09';
        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_staff_admin' => true]))->get($path)->assertOk()
            ->assertSee('TASAF SACCOS monthly statement · September 2026')
            ->assertSee('30 September 2026')->assertSee('1,245,000.50')->assertSee('50,000.00')->assertSee('475,000.00');
        $this->assertDatabaseEmpty('monthly_member_statements');
        Mail::assertNothingOutgoing();
    }
}
