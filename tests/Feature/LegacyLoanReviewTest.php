<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyLoanReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_legacy_installments_and_resolved_repayments_follow_the_correct_member(): void
    {
        $dump = <<<'SQL'
CREATE TABLE `members` (
);
CREATE TABLE `loancategories` (
);
CREATE TABLE `loans` (
);
CREATE TABLE `loanschedules` (
);
CREATE TABLE `repayments` (
);
INSERT INTO `members` (`member_id`) VALUES
(53),
(301);
INSERT INTO `loancategories` (`id`, `category_name`, `category_code`, `default_duration`, `interest_rate`, `status`) VALUES
(1, 'Standard', 'STD', 12, 10, 'active');
INSERT INTO `loans` (`id`, `member_id`, `loan_code`, `loancategory_id`, `loan_status`, `loanInssue_date`, `disbursement_date`, `duration`, `interestmethod_id`, `interest`, `principle`, `repayment_date`, `no_of_installments`) VALUES
(7, 53, 'LOAN-007', 1, 'repayment', NULL, NULL, 12, 1, 10, 1200000, '2026-11-30', 12),
(8, 301, 'OTHER-LOAN', 1, 'repayment', NULL, NULL, 12, 1, 10, 1000000, '2026-11-30', 12);
INSERT INTO `loanschedules` (`id`, `loan_id`, `month`, `monthprinciple`, `monthinterest`, `status`, `r_status`, `duedate`, `scheduled_totalpayment`, `totalpayment`) VALUES
(21, 7, 1, 100000, 10000, 'paid', NULL, '2026-11-30', 110000, 110000),
(22, 99, 1, 100000, 10000, NULL, NULL, '2026-11-30', 110000, 0);
INSERT INTO `repayments` (`id`, `payment_id`, `rploan_id`, `monthly_id`, `monthly_type`, `loanschedule_id`, `amountpayed`, `paymentdate`, `principlepayed`, `interestpayed`) VALUES
(1, 111, 0, NULL, NULL, 21, 110000, '2026-11-30', 100000, 10000),
(2, 222, 8, NULL, NULL, 21, 1, '2026-11-30', 1, 0),
(3, 333, 0, NULL, NULL, 22, 1, '2026-11-30', 1, 0);
SQL;
        $hash = hash('sha256', $dump);
        $path = tempnam(sys_get_temp_dir(), 'legacy-loans-');

        try {
            file_put_contents($path, $dump);
            $options = ['dump' => $path, '--loans' => true, '--stage' => true];
            $this->artisan('legacy:onboarding', $options)->assertSuccessful();
            $this->artisan('legacy:onboarding', $options)->assertSuccessful();
        } finally {
            unlink($path);
        }

        $this->assertDatabaseCount('legacy_onboarding_rows', 8);
        $repayments = DB::table('legacy_onboarding_rows')->where('source_table', 'repayments')->orderBy('source_id')->pluck('data')
            ->map(fn (string $row): array => json_decode($row, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(['schedule_only', 'conflict', 'unresolved'], $repayments->pluck('loan_link')->all());
        $this->assertSame(['7', null, null], $repayments->pluck('resolved_loan_id')->all());

        $member = [
            'first_name' => 'Asha',
            'last_name' => 'Mushi',
            'status' => 'active',
            'legacy_source_sha256' => $hash,
        ];
        $first = DB::table('members')->insertGetId([...$member, 'registration_no' => '10415', 'email' => 'first@example.com', 'legacy_member_id' => 53]);
        $second = DB::table('members')->insertGetId([...$member, 'registration_no' => '10339', 'email' => 'second@example.com', 'legacy_member_id' => 301]);

        $this->get("/staff/members/$first/legacy-loans/7")->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create(['is_staff_admin' => true]));
        $this->get("/staff/members/$first/legacy-loans")->assertOk()->assertSee('LOAN-007')->assertDontSee('OTHER-LOAN');
        $this->get("/staff/members/$second/legacy-loans/7")->assertNotFound();
        $this->get("/staff/members/$first/legacy-loans/7")->assertOk()
            ->assertSee('2026-11-30')
            ->assertSee('110,000.00')
            ->assertSee('>111<', false)
            ->assertDontSee('>222<', false);
    }
}
