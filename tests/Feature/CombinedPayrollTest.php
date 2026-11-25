<?php

namespace Tests\Feature;

use App\MemberFunds;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CombinedPayrollTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_confirmed_tasaf_payroll_updates_loan_savings_and_shares_atomically(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(10, 0));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $reviewer = User::factory()->create(['board_role' => 'secretary']);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415', 'email' => 'asha@example.com', 'status' => 'active',
        ]);
        $opening = DB::table('member_verified_balances')->insertGetId([
            'member_id' => $member, 'as_of' => '2026-11-01', 'savings_minor' => 100000, 'shares_minor' => 0,
            'evidence_reference' => 'Opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('member_fund_transactions')->insert([
            'member_id' => $member, 'member_verified_balance_id' => $opening, 'kind' => 'share_purchase',
            'signed_minor' => 100, 'effective_on' => '2026-11-10', 'reference' => 'ALREADY-USED',
            'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 100000, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 0, 'term_months' => 2, 'interest_method' => 'flat',
            'first_due_date' => '2026-11-30', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        DB::table('loan_disbursements')->insert([
            'loan_application_id' => $loan, 'transfer_reference' => 'TRANSFER-001', 'cash_amount_minor' => 100000,
            'transferred_on' => '2026-11-15', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('loan_application_installments')->insert([
            'loan_application_id' => $loan, 'number' => 1, 'due_date' => '2026-11-30', 'opening_minor' => 100000,
            'principal_minor' => 14000, 'interest_minor' => 1000, 'payment_minor' => 15000, 'closing_minor' => 86000,
        ]);

        DB::table('loan_application_installments')->insert([
            'loan_application_id' => $loan, 'number' => 2, 'due_date' => '2026-12-31', 'opening_minor' => 86000,
            'principal_minor' => 86000, 'interest_minor' => 0, 'payment_minor' => 86000, 'closing_minor' => 0,
        ]);
        $prior = DB::table('loan_repayment_receipts')->insertGetId([
            'loan_application_id' => $loan, 'receipt_reference' => 'PRIOR-RECEIPT', 'received_on' => '2026-11-16',
            'amount_minor' => 5000, 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('loan_repayment_allocations')->insert([
            'loan_application_id' => $loan, 'loan_repayment_receipt_id' => $prior, 'installment_number' => 1,
            'principal_minor' => 5000, 'interest_minor' => 0,
        ]);

        $profile = "/staff/members/$member";
        $this->actingAs($admin)->patch("$profile/payroll-contributions", [
            'payroll_savings' => '50.25', 'payroll_shares' => '10', 'payroll_reference' => 'Member approved deductions',
        ])->assertRedirect($profile);
        $this->get($profile)->assertOk()->assertSee('Member approved deductions');

        $path = '/staff/loan-batches';
        $this->get("$path/new")->assertOk()->assertSee('Generate payroll list')->assertDontSee('CSV');
        $this->post($path, ['kind' => 'repayment', 'period' => '2026-11'])->assertRedirect();
        $this->from("$path/new")->post($path, ['kind' => 'repayment', 'period' => '2026-11'])->assertSessionHasErrors('batch');
        $batch = DB::table('loan_batches')->sole()->id;
        $url = "$path/$batch";
        $this->get($url)->assertOk()->assertSee('Monthly TASAF payroll list')->assertSee('160.25')->assertSee('Savings 50.25');
        $this->get("$url/journals")->assertNotFound();
        $confirmation = ['effective_date' => '2026-11-22', 'payroll_reference' => 'ALREADY-USED'];
        $this->from($url)->post("$url/confirm", $confirmation)->assertSessionHasErrors('batch');
        $this->actingAs($reviewer)->post("$url/review", ['decision' => 'approved', 'review_note' => 'Payroll checked'])->assertRedirect($url);
        $this->assertDatabaseCount('member_fund_transactions', 1);
        $this->assertDatabaseCount('loan_repayment_receipts', 1);

        $this->actingAs($admin)->from($url)->post("$url/confirm", $confirmation)->assertSessionHasErrors('funds');
        $this->assertDatabaseCount('member_fund_transactions', 1);
        $this->assertDatabaseCount('loan_repayment_receipts', 1);
        $this->assertDatabaseHas('loan_batches', ['id' => $batch, 'status' => 'approved']);

        $this->post("$url/confirm", [...$confirmation, 'payroll_reference' => 'NOV-PAYROLL'])->assertRedirect($url);
        $this->assertDatabaseHas('loan_repayment_receipts', ['loan_batch_id' => $batch, 'amount_minor' => 10000, 'receipt_reference' => 'NOV-PAYROLL']);
        $this->assertDatabaseHas('member_fund_transactions', ['loan_batch_id' => $batch, 'kind' => 'savings_deposit', 'signed_minor' => 5025]);
        $this->assertDatabaseHas('member_fund_transactions', ['loan_batch_id' => $batch, 'kind' => 'share_purchase', 'signed_minor' => 1000]);
        $this->assertDatabaseHas('loan_batches', ['id' => $batch, 'status' => 'confirmed']);
        $this->assertSame(105025, app(MemberFunds::class)->balances($member)['savings']);
        $savingsTransaction = DB::table('member_fund_transactions')->where('loan_batch_id', $batch)->where('kind', 'savings_deposit')->sole()->id;
        $this->get($url)->assertOk()->assertSee("/staff/member-fund-journals/$savingsTransaction")
            ->assertSee('View savings journal for 10415')->assertDontSee('View or prepare loan journal');
        $this->get($url)->assertSee('Review and post payment list journals');

        $journalUrl = "$url/journals";
        $this->actingAs($reviewer)->get($journalUrl)->assertForbidden();
        $this->actingAs($admin);
        $accounts = $this->postingAccounts();
        $this->get($journalUrl)->assertOk()->assertViewHas('defaults', []);
        config(['app.demo_mode' => true]);
        $this->get($journalUrl)->assertOk()->assertViewHas('defaults', $accounts)
            ->assertSee('value="'.$accounts['share_purchase'].'" selected', false);
        $this->get("/staff/loan-journal/$batch")->assertOk()->assertViewHas('defaults', $accounts)
            ->assertSee('Review and post payment list journals')
            ->assertSee('value="'.$accounts['offset'].'" selected', false);
        config(['app.demo_mode' => false, 'payroll.posting_accounts.cash' => (string) $accounts['cash']]);
        $this->get($journalUrl)->assertOk()->assertViewHas('defaults', ['cash' => $accounts['cash']]);
        $this->from($journalUrl)->post("$journalUrl/preview", [...$accounts, 'share_purchase' => $accounts['savings_deposit']])
            ->assertSessionHasErrors('journal');
        $this->post("$journalUrl/preview", $accounts)->assertRedirect($journalUrl)->assertSessionHasNoErrors();
        $token = session('payroll_journal_preview.token');
        $this->get($journalUrl)->assertOk()->assertSee('Journal preview')->assertSee('Post all journals');
        $this->assertDatabaseEmpty('loan_journal_postings');
        $this->assertDatabaseEmpty('member_fund_journals');

        $deduction = DB::table('payroll_loan_reconciliations')->insertGetId([
            'payroll_month' => '2026-11-01', 'source_file' => 'November payroll.xlsx',
            'source_sha256' => str_repeat('a', 64), 'source_row' => 7, 'loan_slot' => 1,
            'member_name' => 'Asha Mushi', 'member_id' => $member, 'total_minor' => 10000,
            'principal_minor' => 9000, 'interest_minor' => 1000,
        ]);
        $reconciliation = '/staff/payroll-loan-reconciliation';
        $receiptMatch = ['registration_no' => '10415', 'loan_id' => $loan, 'receipt_reference' => 'NOV-PAYROLL'];
        $this->get($url)->assertOk()->assertSee('Confirmed shares (TZS)')
            ->assertViewHas('payrollTotals', ['savings' => 5025, 'shares' => 1000, 'loans' => 10000])
            ->assertSee('1 loan deduction awaiting reconciliation')
            ->assertSee('/staff/payroll-loan-reconciliation?month=2026-11');
        $this->get("$reconciliation?month=2026-11")->assertOk()->assertSee('November payroll.xlsx')->assertSee('Row 7');
        $this->from($reconciliation)->patch("$reconciliation/$deduction", $receiptMatch)->assertSessionHasErrors('receipt_reference');

        DB::table('ledger_period_events')->insert([
            'period' => '2026-11-01', 'action' => 'closed', 'recorded_by' => $admin->id, 'recorded_at' => now(), 'reason' => 'Month end',
        ]);
        $this->from($journalUrl)->post("$journalUrl/post", ['token' => $token])->assertSessionHasErrors('journal');
        $this->assertDatabaseEmpty('loan_journal_postings');
        $this->assertDatabaseEmpty('member_fund_journals');
        DB::table('ledger_period_events')->insert([
            'period' => '2026-11-01', 'action' => 'reopened', 'recorded_by' => $admin->id, 'recorded_at' => now(), 'reason' => 'Finish payroll',
        ]);

        $this->post("$journalUrl/post", ['token' => $token])->assertRedirect($journalUrl)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('loan_journal_postings', ['loan_batch_id' => $batch, 'posted_by' => $admin->id]);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $accounts['principal'], 'signed_minor' => -9000]);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $accounts['offset'], 'signed_minor' => -1000]);
        $this->assertDatabaseHas('member_fund_journals', [
            'debit_account_id' => $accounts['cash'], 'credit_account_id' => $accounts['savings_deposit'], 'amount_minor' => 5025,
        ]);
        $this->assertDatabaseHas('member_fund_journals', [
            'debit_account_id' => $accounts['cash'], 'credit_account_id' => $accounts['share_purchase'], 'amount_minor' => 1000,
        ]);
        $this->from($journalUrl)->post("$journalUrl/post", ['token' => $token])->assertSessionHasErrors('journal');
        $this->assertDatabaseCount('loan_journal_postings', 1);
        $this->assertDatabaseCount('member_fund_journals', 2);
        $this->get($journalUrl)->assertOk()->assertSee('No outstanding journals.');

        $this->get("/staff/members/$member/funds")->assertOk()
            ->assertSee('Verified opening balance')->assertSee('Posted payroll contributions')
            ->assertViewHas('payroll', fn ($totals): bool => (int) $totals->savings === 5025 && (int) $totals->shares === 1000);
        $this->from($reconciliation)->patch("$reconciliation/$deduction", $receiptMatch)->assertRedirect($reconciliation);
        $this->assertDatabaseHas('payroll_loan_reconciliations', [
            'id' => $deduction, 'reconciled_by' => $admin->id,
            'receipt_id' => DB::table('loan_repayment_receipts')->where('loan_batch_id', $batch)->sole()->id,
        ]);
        $this->get("$reconciliation?status=reconciled")->assertOk()->assertSee('NOV-PAYROLL');
    }

    public function test_payroll_requires_verified_opening_balances_for_configured_contributors(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        DB::table('members')->insert([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415', 'email' => 'asha@example.com',
            'status' => 'active', 'payroll_savings_minor' => 5000,
        ]);

        $path = '/staff/loan-batches';
        $this->actingAs($admin)->from("$path/new")->post($path, ['kind' => 'repayment', 'period' => '2026-11'])
            ->assertRedirect("$path/new")->assertSessionHasErrors(['batch' => 'Verified opening balances are required for configured contributors. Missing balances: 1 (10415).']);
        $this->assertDatabaseEmpty('loan_batches');
    }

    private function postingAccounts(): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Test category']);
        $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Test type', 'account_category_id' => $category]);
        $accounts = [];
        foreach ([
            'cash' => ['1100', 'Demo bank'],
            'principal' => ['1200', 'Loan receivable'],
            'offset' => ['4200', 'Interest income'],
            'savings_deposit' => ['2200', 'Member savings'],
            'share_purchase' => ['2210', 'Member shares'],
        ] as $field => [$code, $name]) {
            $id = count($accounts) + 1;
            $accounts[$field] = DB::table('chart_accounts')->insertGetId([
                ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => $code, 'name' => $name, 'is_ledger_account' => true,
            ]);
        }

        return $accounts;
    }
}
