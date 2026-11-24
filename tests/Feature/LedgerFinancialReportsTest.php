<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LedgerFinancialReportsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_financial_reports_use_chart_categories_and_exclude_opening_values_from_period_income(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$bank, $income, $expenses, $capital, $liability] = $this->accounts();
        $opening = DB::table('ledger_opening_journals')->insertGetId([
            'effective_on' => '2026-11-01', 'review_reference' => 'Approved opening trial balance',
            'source_sha256' => str_repeat('b', 64), 'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        foreach ([$bank => 100000, $capital => -85000, $liability => -10000, $income => -5000] as $account => $amount) {
            DB::table('ledger_opening_lines')->insert([
                'ledger_opening_journal_id' => $opening, 'chart_account_id' => $account, 'signed_minor' => $amount,
            ]);
        }

        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'member@example.com', 'status' => 'active',
        ]);
        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 100000, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 0, 'term_months' => 1, 'interest_method' => 'flat',
            'first_due_date' => '2026-11-30', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        $batch = DB::table('loan_batches')->insertGetId([
            'kind' => 'repayment', 'items' => '[]', 'status' => 'confirmed',
            'prepared_by' => $admin->id, 'prepared_at' => now(), 'effective_date' => '2026-11-10',
        ]);
        $posting = DB::table('loan_journal_postings')->insertGetId([
            'loan_batch_id' => $batch, 'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        $entry = DB::table('loan_journal_entries')->insertGetId([
            'loan_journal_posting_id' => $posting, 'loan_application_id' => $loan,
            'source_reference' => 'NOV-POSTING', 'transaction_date' => '2026-11-10',
        ]);
        foreach ([$bank => 25000, $expenses => 5000, $income => -30000] as $account => $amount) {
            DB::table('loan_journal_lines')->insert([
                'loan_journal_entry_id' => $entry, 'chart_account_id' => $account, 'signed_minor' => $amount,
            ]);
        }

        $path = '/staff/ledger-financial-reports';
        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
        $this->actingAs($admin)->get($path)->assertOk()
            ->assertSee('Income and expenses')->assertSee('Financial position')->assertSee('Print report')
            ->assertSee("/staff/loan-ledger/accounts/$income")->assertDontSee('Accounts to review')
            ->assertViewHas('totals', [
                'income' => 30000, 'expenses' => 5000, 'assets' => 125000, 'liabilities' => 10000,
                'equity' => 85000, 'accumulated_result' => 30000, 'period_result' => 25000,
                'liabilities_and_equity' => 125000, 'difference' => 0,
            ]);
        $this->get("$path?from=2026-11-01&to=2026-11-05")->assertOk()
            ->assertViewHas('totals', fn (array $totals): bool => $totals['income'] === 0
                && $totals['accumulated_result'] === 5000 && $totals['assets'] === 100000);

        $budget = ['year' => 2026, 'account' => $income, 'amount' => '500'];
        $this->post('/staff/budgets', [...$budget, 'account' => $bank])->assertSessionHasErrors('account');
        $this->post('/staff/budgets', $budget)->assertRedirect('/staff/budgets?year=2026');
        $this->post('/staff/budgets', [...$budget, 'amount' => '550'])->assertRedirect('/staff/budgets?year=2026');
        $this->assertDatabaseCount('annual_budget_lines', 1);
        $this->get('/staff/budgets?year=2026')->assertOk()
            ->assertSee('550.00')->assertSee('300.00')->assertSee('-250.00');
        $this->get('/staff/budgets?year=2027')->assertOk()->assertDontSee('550.00');
    }

    public function test_asset_register_tracks_book_value_and_rejects_excess_depreciation(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $asset = ['item' => 'Office computer', 'value' => '1200000', 'depreciation' => '250000.50'];

        $this->actingAs(User::factory()->create())->post('/staff/assets', $asset)->assertForbidden();
        $this->actingAs($admin)->post('/staff/assets', [...$asset, 'depreciation' => '1200000.01'])
            ->assertSessionHasErrors('depreciation');
        $this->post('/staff/assets', $asset)->assertRedirect('/staff/assets');

        $record = DB::table('asset_register')->sole();
        $this->get('/staff/assets')->assertOk()->assertSee('Office computer')->assertSee('949,999.50');
        $this->patch("/staff/assets/$record->id", [...$asset, 'depreciation' => '300000'])->assertRedirect('/staff/assets');
        $this->assertDatabaseHas('asset_register', ['id' => $record->id, 'depreciation_minor' => 30000000]);
    }

    private function accounts(): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $accounts = [];
        foreach (['Asset' => 'Bank', 'Income' => 'Interest income', 'Expenses' => 'Operating expense', 'Capital' => 'Capital', 'Liability' => 'Savings payable'] as $category => $name) {
            $id = count($accounts) + 1;
            $categoryId = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => $id, 'name' => $category]);
            $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => $id, 'name' => 'Type', 'account_category_id' => $categoryId]);
            $accounts[] = DB::table('chart_accounts')->insertGetId([
                ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => (string) $id,
                'name' => $name, 'is_ledger_account' => true,
            ]);
        }

        return $accounts;
    }
}
