<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StageLegacyOnboardingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dry_run_and_repeatable_staging_leave_operational_members_untouched(): void
    {
        $dump = <<<'SQL'
CREATE TABLE `members` (
);
CREATE TABLE `categoryaccounts` (
);
CREATE TABLE `categoryaccountstypes` (
);
CREATE TABLE `mainaccounts` (
);
CREATE TABLE `memberaccounts` (
);
CREATE TABLE `regfees` (
);
INSERT INTO `members` (`member_id`, `registration_no`, `mem_no`, `first_name`, `middle_name`, `last_name`, `email`, `phone`, `joining_date`, `status`, `currency`, `monthsaving`, `monthshare`, `autopay`, `created_at`, `updated_at`, `password`) VALUES
(7, '001', 1, 'Asha', NULL, 'O\'Neil', 'asha@example.com', '', '0000-00-00', 'active', 'TZS', 123.45, 0, 0, NULL, NULL, 'legacy-hash'),
(8, '001', 2, 'John', NULL, 'Mushi', 'john@example.com', '0712345678', '2023-03-01', 'inactive', 'TZS', 0, 0, 0, NULL, NULL, 'legacy-hash');
INSERT INTO `regfees` (`id`, `member_id`, `payment_id`, `amount`, `status`, `date`, `created_at`, `updated_at`) VALUES
(90, 999, NULL, 40000.00, 'unpaid', NULL, NULL, NULL);
SQL;

        $path = tempnam(sys_get_temp_dir(), 'legacy-onboarding-');

        try {
            file_put_contents($path, $dump);

            $this->artisan('legacy:onboarding', ['dump' => $path])
                ->expectsOutput('Dry run complete. No rows written.')
                ->assertSuccessful();
            $this->assertDatabaseEmpty('legacy_onboarding_rows');

            $this->artisan('legacy:onboarding', ['dump' => $path, '--stage' => true])->assertSuccessful();
            $this->artisan('legacy:onboarding', ['dump' => $path, '--stage' => true])->assertSuccessful();
            $this->assertDatabaseCount('legacy_onboarding_rows', 3);
            $this->assertDatabaseEmpty('members');

            $row = DB::table('legacy_onboarding_rows')->where('source_table', 'members')->where('source_id', 7)->first();
            $data = json_decode($row->data, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(hash('sha256', $dump), $row->source_sha256);
            $this->assertSame("O'Neil", $data['last_name']);
            $this->assertSame('123.45', $data['monthsaving']);
            $this->assertArrayNotHasKey('password', $data);
            $this->assertEqualsCanonicalizing(
                ['duplicate_registration_no', 'missing_phone', 'invalid_joining_date'],
                json_decode($row->issues, true, flags: JSON_THROW_ON_ERROR),
            );
            $fee = DB::table('legacy_onboarding_rows')->where('source_table', 'regfees')->first();
            $this->assertSame(['unresolved_member_id'], json_decode($fee->issues, true, flags: JSON_THROW_ON_ERROR));

            file_put_contents($path, rtrim($dump, ';'));
            $this->artisan('legacy:onboarding', ['dump' => $path, '--stage' => true])->assertFailed();
            $this->assertDatabaseCount('legacy_onboarding_rows', 3);
        } finally {
            unlink($path);
        }
    }
}
