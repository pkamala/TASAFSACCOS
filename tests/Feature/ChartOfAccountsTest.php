<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChartOfAccountsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_chart_import_preserves_the_hierarchy_and_staff_can_find_duplicate_codes(): void
    {
        $snapshot = str_repeat('c', 64);
        $account = [
            'name' => 'Member savings',
            'account_no' => '4310',
            'name_sw' => 'Akiba',
            'name_eng' => null,
            'is_ledger_account' => '1',
            'categoryaccountstype_id' => '20',
        ];

        $sources = [
            ['categoryaccounts', 10, ['name' => 'Liabilities', 'code' => 'L']],
            ['categoryaccountstypes', 20, ['name' => 'Member deposits', 'code' => 'D', 'ref_name' => null, 'categoryaccount_id' => '10']],
            ['mainaccounts', 30, [...$account, 'name' => 'Other savings']],
            ['mainaccounts', 31, $account],
        ];
        foreach ($sources as [$table, $id, $data]) {
            DB::table('legacy_onboarding_rows')->insert([
                'source_sha256' => $snapshot,
                'source_table' => $table,
                'source_id' => $id,
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                'issues' => '[]',
            ]);
        }

        $this->artisan('legacy:import-chart', ['snapshot' => $snapshot])->assertSuccessful();
        $this->assertDatabaseEmpty('account_categories');
        $this->assertDatabaseEmpty('chart_accounts');

        $options = ['snapshot' => $snapshot, '--apply' => true];
        $this->artisan('legacy:import-chart', $options)->assertSuccessful();
        $this->artisan('legacy:import-chart', $options)->assertSuccessful();
        $this->assertDatabaseCount('account_categories', 1);
        $this->assertDatabaseCount('account_types', 1);
        $this->assertDatabaseCount('chart_accounts', 2);

        $this->get('/staff/accounts')->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get('/staff/accounts')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_staff_admin' => true]))
            ->get('/staff/accounts?q=4310')
            ->assertOk()
            ->assertSeeInOrder(['Member savings', 'Other savings'])
            ->assertSee('Liabilities')
            ->assertSee('Member deposits')
            ->assertSee('Shared code');

        $this->get('/staff/accounts?q=Akiba&category=999999')
            ->assertOk()
            ->assertSee('No accounts found.');
    }
}
