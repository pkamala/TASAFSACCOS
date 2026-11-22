<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChartAccountManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_replacing_an_account_preserves_posted_history_and_prevents_new_journals_on_the_retired_id(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        [$asset, $liability] = $this->types($source);
        $old = DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => 1, 'account_type_id' => $asset, 'code' => '1110',
            'name' => 'Original cash account', 'is_ledger_account' => true,
        ]);
        $offset = DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => 2, 'account_type_id' => $liability, 'code' => '2110',
            'name' => 'Savings payable', 'is_ledger_account' => true,
        ]);
        $opening = DB::table('ledger_opening_journals')->insertGetId([
            'effective_on' => '2026-11-01', 'review_reference' => 'Opening', 'source_sha256' => str_repeat('b', 64),
            'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        DB::table('ledger_opening_lines')->insert([
            ['ledger_opening_journal_id' => $opening, 'chart_account_id' => $old, 'signed_minor' => 50000],
            ['ledger_opening_journal_id' => $opening, 'chart_account_id' => $offset, 'signed_minor' => -50000],
        ]);

        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'member@example.com', 'status' => 'active',
        ]);
        $verified = DB::table('member_verified_balances')->insertGetId([
            'member_id' => $member, 'as_of' => '2026-10-31', 'savings_minor' => 0, 'shares_minor' => 0,
            'evidence_reference' => 'Opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $transaction = DB::table('member_fund_transactions')->insertGetId([
            'member_id' => $member, 'member_verified_balance_id' => $verified, 'kind' => 'savings_deposit',
            'signed_minor' => 10000, 'effective_on' => '2026-11-22', 'reference' => 'RECEIPT-001',
            'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);

        $path = '/staff/accounts';
        $journal = "/staff/member-fund-journals/$transaction";
        $changes = ['code' => '1110', 'name' => 'New cash account', 'account_type_id' => $liability, 'reason' => 'Reclassified prospectively'];
        $this->get("$path/$old")->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->post("$path/$old/replacement", $changes)->assertForbidden();
        $this->actingAs($admin)->get("$path/$old/replacement")->assertOk()->assertSee('Original cash account');

        $this->post("$journal/preview", ['cash_account' => $old, 'member_account' => $offset])->assertRedirect($journal);
        $previewToken = session('member_fund_journal_preview.token');
        $this->post("$path/$old/replacement", $changes)->assertRedirect();
        $replacement = DB::table('chart_accounts')->where('replaces_account_id', $old)->sole();
        $this->assertDatabaseHas('chart_accounts', [
            'id' => $old, 'name' => 'Original cash account', 'code' => '1110', 'account_type_id' => $asset,
            'retired_by' => $admin->id, 'retirement_reason' => $changes['reason'],
        ]);
        $this->assertSame($liability, $replacement->account_type_id);
        $this->assertDatabaseHas('ledger_opening_lines', ['chart_account_id' => $old, 'signed_minor' => 50000]);

        $this->from($journal)->post("$journal/post", ['token' => $previewToken])->assertSessionHasErrors('journal');
        $this->assertDatabaseEmpty('member_fund_journals');
        $this->get($path)->assertOk()->assertSee('New cash account')->assertDontSee('Original cash account');
        $this->get("$path?status=retired")->assertOk()->assertSee('Original cash account')->assertDontSee('New cash account');
        $this->get("$path/$old")->assertOk()->assertSee($changes['reason'])->assertSee('New cash account');
        $this->get('/staff/ledger-financial-reports')->assertOk()->assertSee('Original cash account')
            ->assertViewHas('totals', fn (array $totals): bool => $totals['assets'] === 50000);

        $this->from("$path/new")->post($path, $changes)->assertSessionHasErrors('code');
        $this->post($path, [...$changes, 'name' => 'Another fund account', 'code' => '3120'])->assertRedirect();
        $this->assertDatabaseHas('chart_accounts', ['code' => '3120', 'legacy_id' => null, 'created_by' => $admin->id]);

        $this->post("$journal/preview", ['cash_account' => $replacement->id, 'member_account' => $offset])->assertRedirect();
        $this->post("$journal/post", ['token' => session('member_fund_journal_preview.token')])->assertRedirect($journal);
        $this->assertDatabaseHas('member_fund_journals', ['member_fund_transaction_id' => $transaction, 'debit_account_id' => $replacement->id]);
    }

    public function test_admin_can_transfer_a_retired_balance_without_rewriting_earlier_activity(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(12, 0));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        [$asset, $liability] = $this->types($source);
        $original = DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => 1, 'account_type_id' => $asset, 'code' => '1110',
            'name' => 'Original cash account', 'is_ledger_account' => true,
        ]);
        $offset = DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => 2, 'account_type_id' => $liability, 'code' => '2110',
            'name' => 'Savings payable', 'is_ledger_account' => true,
        ]);
        $opening = DB::table('ledger_opening_journals')->insertGetId([
            'effective_on' => '2026-11-01', 'review_reference' => 'Opening', 'source_sha256' => str_repeat('b', 64),
            'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        DB::table('ledger_opening_lines')->insert([
            ['ledger_opening_journal_id' => $opening, 'chart_account_id' => $original, 'signed_minor' => 50000],
            ['ledger_opening_journal_id' => $opening, 'chart_account_id' => $offset, 'signed_minor' => -50000],
        ]);

        $path = "/staff/accounts/$original/balance-transfer";
        $payload = ['transaction_date' => '2026-11-22', 'reason' => 'Move the retired cash balance'];
        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->post("$path/preview", $payload)->assertForbidden();
        $this->actingAs($admin)->post("/staff/accounts/$original/replacement", [
            'code' => '1120', 'name' => 'Replacement cash account', 'account_type_id' => $asset, 'reason' => 'Update cash account code',
        ])->assertRedirect();
        $replacement = DB::table('chart_accounts')->where('replaces_account_id', $original)->sole()->id;

        $this->from($path)->post("$path/preview", [...$payload, 'transaction_date' => '2026-11-21'])
            ->assertSessionHasErrors('transfer');
        $this->post("$path/preview", $payload)->assertRedirect("$path#transfer-preview");
        $this->get($path)->assertOk()->assertSee('Transfer preview')->assertSee('500.00');
        $token = session('account_transfer_preview.token');
        $this->assertDatabaseEmpty('chart_account_transfers');

        $this->post("$path/post", ['token' => $token])->assertRedirect();
        $transfer = DB::table('chart_account_transfers')->sole();
        $this->assertDatabaseHas('chart_account_transfers', [
            'original_account_id' => $original, 'replacement_account_id' => $replacement,
            'transaction_date' => $payload['transaction_date'], 'reason' => $payload['reason'],
            'signed_minor' => 50000, 'posted_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('ledger_opening_lines', ['chart_account_id' => $original, 'signed_minor' => 50000]);
        $this->get("/staff/account-transfers/$transfer->id")->assertOk()->assertSee($payload['reason']);
        $this->get("/staff/loan-ledger/accounts/$original?from=2026-11-01&to=2026-11-21")
            ->assertOk()->assertViewHas('closingBalance', 50000);
        $this->get("/staff/loan-ledger/accounts/$original?from=2026-11-01&to=2026-11-22")
            ->assertOk()->assertViewHas('closingBalance', 0);
        $this->get("/staff/loan-ledger/accounts/$replacement?from=2026-11-01&to=2026-11-22")
            ->assertOk()->assertViewHas('closingBalance', 50000);
        $this->from($path)->post("$path/post", ['token' => $token])->assertSessionHasErrors('transfer');
        $this->assertDatabaseCount('chart_account_transfers', 1);
    }

    private function types(array $source): array
    {
        return array_map(function (string $name, int $id) use ($source): int {
            $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => $id, 'name' => $name]);

            return DB::table('account_types')->insertGetId([...$source, 'legacy_id' => $id, 'name' => $name, 'account_category_id' => $category]);
        }, ['Asset', 'Liability'], [1, 2]);
    }
}
