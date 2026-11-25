<?php

namespace Tests\Feature;

use App\LedgerReportData;
use App\MemberExitSettlement;
use App\MemberFunds;
use App\Models\User;
use App\MonthlyStatementDrafts;
use Carbon\CarbonImmutable;
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

    public function test_monthly_deposit_interest_is_credited_once_and_does_not_compound(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 25));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active',
        ]);
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-09-01', 'savings_minor' => 0, 'shares_minor' => 0,
            'evidence_reference' => 'Verified balance', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        [$expense, $deposits] = $this->accounts(['Deposit interest expense', 'Member deposits', 'Shares']);
        $funds = "/staff/members/$member/funds";
        $prepare = "$funds/deposit-interest";
        $receipt = ['kind' => 'member_deposit', 'amount' => '50000000', 'reference' => 'DEP-SEP', 'effective_on' => '2026-09-30'];

        $this->actingAs($admin)->from($funds)->post($funds, $receipt)->assertSessionHasNoErrors()->assertRedirect($funds);
        $this->post($funds, [...$receipt, 'amount' => '1', 'reference' => 'DEP-OCT', 'effective_on' => '2026-10-01'])
            ->assertSessionHasNoErrors()->assertRedirect($funds);
        $this->post($prepare, ['month' => '2026-11'])->assertSessionHasErrors('month');
        $this->post($prepare, ['month' => '2026-09'])->assertSessionHasNoErrors()->assertRedirect();
        $credit = DB::table('member_fund_transactions')->where('kind', 'deposit_interest')->sole();
        $journal = "/staff/member-fund-journals/$credit->id";
        $this->assertSame(8_333_333, $credit->signed_minor);
        $this->assertSame('2026-09-30', $credit->effective_on);
        $this->post($prepare, ['month' => '2026-09'])->assertRedirect($journal);
        $this->post($funds, $receipt)->assertSessionHasNoErrors()->assertRedirect();
        $this->post($funds, [...$receipt, 'reference' => 'BACKDATED'])->assertSessionHasErrors('effective_on');
        $this->assertSame(5_000_000_100, app(MemberFunds::class)->balances($member)['deposits']);
        $this->get($journal)->assertOk()->assertSee('Deposit interest expense')->assertSee('at 2% per year');
        $this->post("$journal/preview", ['cash_account' => $expense, 'member_account' => $deposits])->assertRedirect($journal);
        $this->post("$journal/post", ['token' => session('member_fund_journal_preview.token')])->assertRedirect($journal);
        $this->assertSame(5_008_333_433, app(MemberFunds::class)->balances($member)['deposits']);
        $this->assertDatabaseHas('member_fund_journals', [
            'member_fund_transaction_id' => $credit->id, 'debit_account_id' => $expense, 'credit_account_id' => $deposits,
        ]);

        $this->post($prepare, ['month' => '2026-10'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('member_fund_transactions', [
            'kind' => 'deposit_interest', 'effective_on' => '2026-10-31', 'signed_minor' => 12_500_000,
        ]);
        $this->assertDatabaseCount('member_fund_transactions', 4);
    }

    public function test_monthly_savings_interest_posts_net_credit_and_reverses_all_journal_lines(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 25));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active',
        ]);
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-09-01', 'savings_minor' => 1000000, 'shares_minor' => 0,
            'evidence_reference' => 'Verified balance', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        [$expense, $savings, $tax] = $this->accounts(['Savings interest expense', 'Member savings', 'Withholding payable']);
        $funds = "/staff/members/$member/funds";
        $prepare = "$funds/savings-interest";
        $september = ['savings_month' => '2026-09'];
        $today = CarbonImmutable::today('Africa/Dar_es_Salaam');
        $balances = app(MemberFunds::class);
        $settlement = app(MemberExitSettlement::class);

        $this->actingAs(User::factory()->create())->post($prepare, $september)->assertForbidden();
        $this->actingAs($admin)->get($funds)->assertOk()->assertSee('Prepare savings interest');
        $this->from($funds)->post($prepare, ['savings_month' => '2026-11'])->assertSessionHasErrors('savings_month');
        $this->post($prepare, $september)->assertSessionHasNoErrors()->assertRedirect();

        $credit = DB::table('member_fund_transactions')->where('kind', 'savings_interest')->sole();
        $journal = "/staff/member-fund-journals/$credit->id";
        $this->assertSame(5000, $credit->signed_minor);
        $this->assertSame(500, $credit->withholding_minor);
        $this->assertSame(1000000, $balances->balances($member)['savings']);
        $this->assertSame(10000, $settlement->draft($member, $today)['interest']['gross']);
        $this->post($prepare, $september)->assertRedirect($journal);
        $this->from($funds)->post($funds, [
            'kind' => 'savings_deposit', 'amount' => '50', 'reference' => 'BACKDATED', 'effective_on' => '2026-09-30',
        ])->assertSessionHasErrors('effective_on');

        $this->get($journal)->assertOk()->assertSee('Savings interest expense')->assertSee('Withholding payable account');
        $accounts = ['cash_account' => $expense, 'member_account' => $savings];
        $this->from($journal)->post("$journal/preview", $accounts)->assertSessionHasErrors('withholding_account');
        $this->post("$journal/preview", [...$accounts, 'withholding_account' => $tax])->assertRedirect($journal);
        $this->get($journal)->assertOk()->assertSee('45.00')->assertSee('5.00');
        $this->post("$journal/post", ['token' => session('member_fund_journal_preview.token')])->assertRedirect($journal);
        $this->assertSame(1004500, $balances->balances($member)['savings']);
        $this->assertSame(1004500, (int) app(MonthlyStatementDrafts::class)->forMonth('2026-10-01', '2026-10-31')->sole()->savings_minor);
        $this->assertSame(5000, $settlement->draft($member, $today)['interest']['gross']);

        $lines = app(LedgerReportData::class)->lines()->where('source_kind', 'member_fund')->orderBy('chart_account_id');
        $this->assertSame([$expense => 5000, $savings => -4500, $tax => -500], $lines->pluck('signed_minor', 'chart_account_id')->all());
        $posting = DB::table('member_fund_journals')->sole();
        $this->post($prepare, ['savings_month' => '2026-10'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('member_fund_transactions', [
            'kind' => 'savings_interest', 'effective_on' => '2026-10-31', 'signed_minor' => 5000,
        ]);

        $this->post("/staff/ledger-reversals/member-fund/$posting->id", [
            'transaction_date' => '2026-11-25', 'reason' => 'Correct the interest journal',
        ])->assertRedirect();
        $reversal = DB::table('ledger_journal_reversal_lines')->orderBy('chart_account_id')->pluck('signed_minor', 'chart_account_id')->all();
        $this->assertSame([$expense => -5000, $savings => 4500, $tax => 500], $reversal);
        $this->assertSame(1000000, $balances->balances($member)['savings']);
        $this->assertSame(1000000, (int) app(MonthlyStatementDrafts::class)->forMonth('2026-11-01', '2026-11-30')->sole()->savings_minor);
        $this->assertSame(10000, $settlement->draft($member, $today)['interest']['gross']);
    }

    private function accounts(array $names = ['Bank', 'Savings', 'Shares']): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Category']);
        $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Type', 'account_category_id' => $category]);

        return array_map(fn (string $name, int $id): int => DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => (string) $id, 'name' => $name, 'is_ledger_account' => true,
        ]), $names, [1, 2, 3]);
    }
}
