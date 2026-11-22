<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerPeriodTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_period_close_requires_posted_transactions_and_reopening_preserves_audit_history(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'member@example.com', 'status' => 'active',
        ]);
        $opening = DB::table('member_verified_balances')->insertGetId([
            'member_id' => $member, 'as_of' => '2026-09-30', 'savings_minor' => 0, 'shares_minor' => 0,
            'evidence_reference' => 'Opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $transaction = DB::table('member_fund_transactions')->insertGetId([
            'member_id' => $member, 'member_verified_balance_id' => $opening, 'kind' => 'savings_deposit',
            'signed_minor' => 50000, 'effective_on' => '2026-10-15', 'reference' => 'OCT-001',
            'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        [$bank, $offset] = $this->accounts();
        $path = '/staff/ledger-periods';
        $filter = "$path?month=2026-10";
        $close = ['month' => '2026-10', 'action' => 'close', 'reason' => 'October reconciled'];

        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->post($path, $close)->assertForbidden();
        $this->actingAs($admin)->get($filter)->assertOk()->assertSee('1 savings and share transactions awaiting a journal')->assertDontSee('Close month');
        $this->from($filter)->post($path, $close)->assertSessionHasErrors('action');
        $this->post($path, [...$close, 'month' => '2026-11'])->assertSessionHasErrors('month');
        $this->assertDatabaseEmpty('ledger_period_events');

        $journal = DB::table('member_fund_journals')->insertGetId([
            'member_fund_transaction_id' => $transaction, 'debit_account_id' => $bank, 'credit_account_id' => $offset,
            'amount_minor' => 50000, 'transaction_date' => '2026-10-15', 'source_reference' => 'OCT-001',
            'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        $this->post($path, $close)->assertRedirect($filter);
        $this->get($filter)->assertOk()->assertSee('Reopen month')->assertSee('October reconciled');
        $this->assertDatabaseHas('ledger_period_events', ['period' => '2026-10-01', 'action' => 'closed', 'recorded_by' => $admin->id]);

        $deposit = ['kind' => 'savings_deposit', 'amount' => '25', 'reference' => 'OCT-002', 'effective_on' => '2026-10-31'];
        $this->from($filter)->post("/staff/members/$member/funds", $deposit)->assertSessionHasErrors('effective_on');
        $reversal = "/staff/ledger-reversals/member-fund/$journal";
        $this->from($reversal)->post($reversal, ['transaction_date' => '2026-10-31', 'reason' => 'Adjustment'])
            ->assertSessionHasErrors('transaction_date');
        $this->assertDatabaseEmpty('ledger_journal_reversals');
        $this->post($reversal, ['transaction_date' => '2026-11-01', 'reason' => 'Adjustment in open month'])->assertRedirect();

        $this->post($path, [...$close, 'action' => 'reopen', 'reason' => 'Late verified receipt'])->assertRedirect($filter);
        $this->assertDatabaseHas('ledger_period_events', ['period' => '2026-10-01', 'action' => 'reopened', 'reason' => 'Late verified receipt']);
        $this->post("/staff/members/$member/funds", $deposit)->assertRedirect();
        $this->assertDatabaseHas('member_fund_transactions', ['reference' => 'OCT-002', 'effective_on' => '2026-10-31']);
        $this->assertDatabaseCount('ledger_period_events', 2);
    }

    private function accounts(): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Category']);
        $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Type', 'account_category_id' => $category]);

        return array_map(fn (string $name, int $id): int => DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => (string) $id, 'name' => $name, 'is_ledger_account' => true,
        ]), ['Bank', 'Offset'], [1, 2]);
    }
}
