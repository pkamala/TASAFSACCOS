<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyMemberTransactionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_can_review_member_scoped_savings_shares_and_monthly_contributions(): void
    {
        $dump = <<<'SQL'
CREATE TABLE `members` (
);
CREATE TABLE `memberaccounts` (
);
CREATE TABLE `membersavingaccounts` (
);
CREATE TABLE `membershareaccounts` (
);
CREATE TABLE `monthpaymentlists` (
);
INSERT INTO `members` (`member_id`) VALUES
(53),
(301);
INSERT INTO `memberaccounts` (`id`, `member_id`) VALUES
(77, 53);
INSERT INTO `membersavingaccounts` (`id`, `member_id`, `memberaccount_id`, `payment_id`, `monthly_id`, `monthly_type`, `dr`, `cr`, `description`, `date`) VALUES
(1, 53, 77, 15, 90, 'payroll', 0, 25000, 'Member deposit', '2026-11-10'),
(2, 301, 77, 16, NULL, NULL, 0, 50000, 'Different member deposit', '2026-11-10');
INSERT INTO `membershareaccounts` (`id`, `member_id`, `memberaccount_id`, `payment_id`, `monthly_id`, `monthly_type`, `dr`, `cr`, `description`, `date`, `no_shares`) VALUES
(3, 53, 999, 15, 90, 'payroll', 0, 10000, 'Share purchase', '0000-00-00', 2);
INSERT INTO `monthpaymentlists` (`id`, `member_id`, `payment_id`, `date`, `month`, `year`, `shares`, `savings`, `status`) VALUES
(90, 53, 15, '2026-11-10', 11, 2026, 10000, 25000, 'paid');
SQL;
        $path = tempnam(sys_get_temp_dir(), 'legacy-savings-');
        try {
            file_put_contents($path, $dump);
            $options = ['dump' => $path, '--savings' => true];
            $this->artisan('legacy:onboarding', $options)->assertSuccessful();
            $this->assertDatabaseEmpty('legacy_onboarding_rows');
            $this->artisan('legacy:onboarding', [...$options, '--stage' => true])->assertSuccessful();
        } finally {
            unlink($path);
        }
        $this->assertDatabaseCount('legacy_onboarding_rows', 4);

        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Mushi', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active',
            'legacy_member_id' => 53, 'legacy_source_sha256' => hash('sha256', $dump),
        ]);
        $url = "/staff/members/$member/legacy-savings";
        $this->get($url)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_staff_admin' => true]))->get($url)
            ->assertOk()->assertSee('Member deposit')->assertSee('25,000.00')->assertDontSee('Different member deposit');
        $this->get("$url?section=shares")->assertOk()
            ->assertSee('Share purchase')->assertSee('Unresolved memberaccount id')->assertSee('Invalid transaction date');
        $this->get("$url?section=contributions")->assertOk()
            ->assertSee('2026-11')->assertSee('paid')->assertSee('25,000.00');
    }
}
