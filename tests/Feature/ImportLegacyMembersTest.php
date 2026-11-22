<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportLegacyMembersTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_explicit_renumbering_preserves_both_member_identities(): void
    {
        $snapshot = str_repeat('a', 64);
        $member = [
            'registration_no' => '10339',
            'first_name' => 'Asha',
            'middle_name' => null,
            'last_name' => 'Mushi',
            'email' => 'asha@example.com',
            'phone' => '',
            'joining_date' => '0000-00-00',
            'status' => 'inactive',
        ];

        $source = [
            'source_sha256' => $snapshot,
            'source_table' => 'members',
            'issues' => json_encode(['invalid_joining_date'], JSON_THROW_ON_ERROR),
        ];

        DB::table('legacy_onboarding_rows')->insert([
            [...$source, 'source_id' => 53, 'data' => json_encode($member, JSON_THROW_ON_ERROR)],
            [...$source, 'source_id' => 301, 'data' => json_encode([
                ...$member,
                'email' => 'active@example.com',
                'status' => 'active',
            ], JSON_THROW_ON_ERROR)],
        ]);

        $this->artisan('legacy:import-members', ['snapshot' => $snapshot, '--apply' => true])->assertFailed();
        $this->assertDatabaseEmpty('members');

        $arguments = ['snapshot' => $snapshot, '--renumber' => ['53=10415']];
        $this->artisan('legacy:import-members', $arguments)->assertSuccessful();
        $this->assertDatabaseEmpty('members');

        $this->artisan('legacy:import-members', [...$arguments, '--apply' => true])->assertSuccessful();
        $this->assertDatabaseHas('members', [
            'legacy_member_id' => 53,
            'legacy_source_sha256' => $snapshot,
            'legacy_registration_no' => '10339',
            'registration_no' => '10415',
            'phone' => null,
            'joining_date' => null,
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('members', [
            'legacy_member_id' => 301,
            'registration_no' => '10339',
            'status' => 'active',
        ]);

        $this->artisan('legacy:import-members', [...$arguments, '--apply' => true])
            ->expectsOutput('0 legacy members imported.')
            ->assertSuccessful();
        $this->assertDatabaseCount('members', 2);
    }
}
