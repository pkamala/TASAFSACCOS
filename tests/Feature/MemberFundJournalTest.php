<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberFundJournalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_savings_share_and_withdrawal_journals_post_once_and_flow_into_the_trial_balance(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active',
        ]);
        $opening = DB::table('member_verified_balances')->insertGetId([
            'member_id' => $member, 'as_of' => '2026-10-31', 'savings_minor' => 100000, 'shares_minor' => 0,
            'evidence_reference' => 'Verified balance', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $withdrawal = DB::table('member_savings_withdrawals')->insertGetId([
            'member_id' => $member, 'amount_minor' => 25000, 'status' => 'paid',
            'requested_by' => $admin->id, 'reason' => 'Savings withdrawal', 'requested_at' => now(),
        ]);
        $record = [
            'member_id' => $member, 'member_verified_balance_id' => $opening, 'effective_on' => '2026-11-22',
            'recorded_by' => $admin->id, 'recorded_at' => now(),
        ];
        $depositId = DB::table('member_fund_transactions')->insertGetId([
            ...$record, 'kind' => 'savings_deposit', 'signed_minor' => 50000, 'reference' => 'DEPOSIT-001',
        ]);
        $purchaseId = DB::table('member_fund_transactions')->insertGetId([
            ...$record, 'kind' => 'share_purchase', 'signed_minor' => 10000, 'reference' => 'SHARE-001',
        ]);
        $payoutId = DB::table('member_fund_transactions')->insertGetId([
            ...$record, 'kind' => 'savings_withdrawal', 'signed_minor' => -25000, 'reference' => 'PAYOUT-001',
            'member_savings_withdrawal_id' => $withdrawal,
        ]);
        [$cash, $savings, $shares] = $this->accounts();
        $path = '/staff/member-fund-journals';

        $this->get("$path/$depositId")->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get("$path/$depositId")->assertForbidden();
        $this->actingAs($admin)->from("$path/$depositId")->post("$path/$depositId/preview", [
            'cash_account' => $cash, 'member_account' => $cash,
        ])->assertSessionHasErrors('member_account');
        $this->post("$path/$depositId/preview", ['cash_account' => $cash, 'member_account' => $savings])->assertRedirect("$path/$depositId");
        $token = session('member_fund_journal_preview.token');
        $this->assertDatabaseEmpty('member_fund_journals');
        $this->get("$path/$depositId")->assertOk()->assertSee('Preview journal lines')->assertSee('500.00');
        $this->post("$path/$depositId/post", ['token' => $token])->assertRedirect("$path/$depositId");
        $this->from("$path/$depositId")->post("$path/$depositId/post", ['token' => $token])->assertSessionHasErrors('journal');

        foreach ([$purchaseId => $shares, $payoutId => $savings] as $transaction => $account) {
            $this->post("$path/$transaction/preview", ['cash_account' => $cash, 'member_account' => $account])->assertRedirect();
            $this->post("$path/$transaction/post", ['token' => session('member_fund_journal_preview.token')])->assertRedirect("$path/$transaction");
        }

        $this->assertDatabaseCount('member_fund_journals', 3);
        $this->assertDatabaseHas('member_fund_journals', [
            'member_fund_transaction_id' => $payoutId, 'debit_account_id' => $savings,
            'credit_account_id' => $cash, 'amount_minor' => 25000,
        ]);
        $this->get('/staff/loan-ledger')->assertOk()->assertSee('SACCOS ledger trial balance')
            ->assertViewHas('totals', [
                'opening_debit' => 0, 'opening_credit' => 0, 'debit' => 85000,
                'credit' => 85000, 'closing_debit' => 35000, 'closing_credit' => 35000,
            ]);
        $this->get("/staff/loan-ledger/accounts/$cash")->assertOk()
            ->assertSee('350.00 Dr')->assertSee('PAYOUT-001')->assertSee("$path/$payoutId");
    }

    private function accounts(): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Category']);
        $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Type', 'account_category_id' => $category]);

        return array_map(fn (string $name, int $id): int => DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => (string) $id, 'name' => $name, 'is_ledger_account' => true,
        ]), ['Bank', 'Savings', 'Shares'], [1, 2, 3]);
    }
}
