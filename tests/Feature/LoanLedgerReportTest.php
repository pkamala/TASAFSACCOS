<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoanLedgerReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_trial_balance_and_account_activity_use_posted_dates_and_carry_opening_balances(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10074',
            'email' => 'member@example.com', 'status' => 'active',
        ]);
        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 100000, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 0, 'term_months' => 1, 'interest_method' => 'flat',
            'first_due_date' => '2026-11-30', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        [$bank, $receivable] = $this->accounts();
        $this->entry($loan, $admin->id, '2026-10-01', 'OPENING-TRANSFER', [$receivable => 100000, $bank => -100000]);
        $inPeriod = $this->entry($loan, $admin->id, '2026-11-15', 'PAYROLL-1115', [$bank => 25000, $receivable => -25000]);
        $this->entry($loan, $admin->id, '2026-11-20', 'AFTER-CUTOFF', [$bank => 15000, $receivable => -15000]);

        $path = '/staff/loan-ledger';
        $filter = '?from=2026-11-01&to=2026-11-15';
        $this->get("$path$filter")->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get("$path$filter")->assertForbidden();

        $this->actingAs($admin)->get("$path$filter")->assertOk()
            ->assertSee('Print report')->assertSee('Loan receivable')->assertSee('15-NOV-2026')
            ->assertSeeInOrder(['TRANS_DATE', 'GRP', 'TRANS_DATE1', 'ACCOUNT', 'DESCRIPTION', 'GL_BFWBAL', 'DEBITS', 'CREDITS', 'NET_CHANGE', 'ENDING_BAL'])
            ->assertSee('-250.00')
            ->assertDontSee('<tfoot>', false)
            ->assertViewHas('accounts', fn ($rows): bool => (int) $rows->firstWhere('id', $receivable)->closing_minor === 75000
                && (int) $rows->firstWhere('id', $bank)->closing_minor === -75000);
        $this->get("$path/accounts/$receivable$filter")->assertOk()
            ->assertSee('1,000.00 Dr')->assertSee('750.00 Dr')->assertSee('PAYROLL-1115')
            ->assertSee("/staff/loan-journal/$inPeriod")->assertDontSee('AFTER-CUTOFF')
            ->assertDontSee('OPENING-TRANSFER');
        $this->get("$path/accounts/$bank?from=2026-11-02&to=2026-11-03")->assertOk()
            ->assertSee('1,000.00 Cr')->assertSee('No posted entries for this account');
    }

    private function accounts(): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Category']);
        $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Type', 'account_category_id' => $category]);

        return array_map(fn (string $name, int $id): int => DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => (string) $id, 'name' => $name, 'is_ledger_account' => true,
        ]), ['Bank', 'Loan receivable'], [1, 2]);
    }

    private function entry(int $loan, int $actor, string $date, string $reference, array $amounts): int
    {
        $batch = DB::table('loan_batches')->insertGetId([
            'kind' => 'repayment', 'items' => '[]', 'status' => 'confirmed', 'prepared_by' => $actor,
            'prepared_at' => now(), 'effective_date' => $date,
        ]);
        $posting = DB::table('loan_journal_postings')->insertGetId([
            'loan_batch_id' => $batch, 'posted_by' => $actor, 'posted_at' => now(),
        ]);
        $entry = DB::table('loan_journal_entries')->insertGetId([
            'loan_journal_posting_id' => $posting, 'loan_application_id' => $loan,
            'transaction_date' => $date, 'source_reference' => $reference,
        ]);
        foreach ($amounts as $account => $amount) {
            DB::table('loan_journal_lines')->insert([
                'loan_journal_entry_id' => $entry, 'chart_account_id' => $account, 'signed_minor' => $amount,
            ]);
        }

        return $batch;
    }
}
