<?php

namespace Tests\Feature;

use App\Mail\MonthlyMemberStatementMail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MonthlyMemberStatementsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_reviewed_statement_is_imported_and_emailed_once_for_a_closed_month(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 5));
        $memberId = DB::table('members')->insertGetId([
            'registration_no' => '10415',
            'first_name' => 'Asha',
            'last_name' => 'Mushi',
            'email' => 'asha@example.com',
            'status' => 'inactive',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'monthly-statement-');
        $header = "registration_no,email,loan_balance,monthly_contribution,savings_balance\n";
        $args = ['period' => '2026-10', 'csv' => $path];

        try {
            file_put_contents($path, $header."10415,wrong@example.com,1000000.25,25000,350000\n");
            $this->artisan('statements:import', [...$args, '--apply' => true])->assertFailed();
            $this->assertDatabaseEmpty('monthly_member_statements');

            file_put_contents($path, $header."10415,asha@example.com,1000000.25,25000,350000\n");
            $this->artisan('statements:import', $args)->assertSuccessful();
            $this->assertDatabaseEmpty('monthly_member_statements');
            $this->artisan('statements:import', [...$args, '--apply' => true])->assertSuccessful();
            $this->artisan('statements:import', [...$args, '--apply' => true])->expectsOutput('0 statements imported.')->assertSuccessful();
        } finally {
            unlink($path);
        }

        $this->assertDatabaseHas('monthly_member_statements', [
            'member_id' => $memberId,
            'period' => '2026-10-01',
            'loan_balance_minor' => 100000025,
            'delivery_status' => 'pending',
        ]);

        Mail::fake();
        config(['mail.default' => 'log']);
        $this->artisan('statements:send', ['--send' => true])->assertFailed();
        Mail::assertNothingOutgoing();

        config(['mail.default' => 'smtp']);
        $this->artisan('statements:send')->assertSuccessful();
        Mail::assertNothingOutgoing();

        DB::table('members')->where('id', $memberId)->update(['email' => 'changed@example.com']);
        $this->artisan('statements:send', ['--send' => true])->assertFailed();
        Mail::assertNothingOutgoing();

        DB::table('members')->where('id', $memberId)->update(['email' => 'asha@example.com']);
        $this->artisan('statements:send', ['--send' => true])->assertSuccessful();
        $this->artisan('statements:send', ['--send' => true])->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(MonthlyMemberStatementMail::class, fn (MonthlyMemberStatementMail $mail): bool => $mail->hasTo('asha@example.com')
            && $mail->month === 'October 2026'
            && $mail->contribution === 2500000
            && $mail->savingsBalance === 35000000);
        $this->assertDatabaseHas('monthly_member_statements', ['member_id' => $memberId, 'delivery_status' => 'sent']);
    }

    public function test_the_email_renders_each_amount_and_the_statement_date(): void
    {
        $mail = new MonthlyMemberStatementMail('October 2026', '31 October 2026', 100000025, 2500000, 35000000);

        $mail->assertSeeInHtml('31 October 2026')
            ->assertSeeInHtml('1,000,000.25')
            ->assertSeeInHtml('25,000.00')
            ->assertSeeInHtml('350,000.00');
        $mail->assertSeeInText('Outstanding loan balance');
    }
}
