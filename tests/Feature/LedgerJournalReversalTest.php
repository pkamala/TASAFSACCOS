<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LedgerJournalReversalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reversals_offset_both_original_journal_types_and_preserve_sources(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'member@example.com', 'status' => 'active',
        ]);
        [$bank, $offset] = $this->accounts();
        $opening = DB::table('member_verified_balances')->insertGetId([
            'member_id' => $member, 'as_of' => '2026-10-31', 'savings_minor' => 0, 'shares_minor' => 0,
            'evidence_reference' => 'Opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $transaction = DB::table('member_fund_transactions')->insertGetId([
            'member_id' => $member, 'member_verified_balance_id' => $opening, 'kind' => 'savings_deposit',
            'signed_minor' => 50000, 'effective_on' => '2026-11-10', 'reference' => 'DEPOSIT-001',
            'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $fund = DB::table('member_fund_journals')->insertGetId([
            'member_fund_transaction_id' => $transaction, 'debit_account_id' => $bank, 'credit_account_id' => $offset,
            'amount_minor' => 50000, 'transaction_date' => '2026-11-10', 'source_reference' => 'DEPOSIT-001',
            'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 30000, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 0, 'term_months' => 1, 'interest_method' => 'flat',
            'first_due_date' => '2026-11-30', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        $batch = DB::table('loan_batches')->insertGetId([
            'kind' => 'disbursement', 'items' => '[]', 'status' => 'confirmed', 'prepared_by' => $admin->id,
            'prepared_at' => now(), 'effective_date' => '2026-11-15',
        ]);
        $posting = DB::table('loan_journal_postings')->insertGetId([
            'loan_batch_id' => $batch, 'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        $entry = DB::table('loan_journal_entries')->insertGetId([
            'loan_journal_posting_id' => $posting, 'loan_application_id' => $loan,
            'transaction_date' => '2026-11-15', 'source_reference' => 'BANK-001',
        ]);
        DB::table('loan_journal_lines')->insert([
            ['loan_journal_entry_id' => $entry, 'chart_account_id' => $bank, 'signed_minor' => -30000],
            ['loan_journal_entry_id' => $entry, 'chart_account_id' => $offset, 'signed_minor' => 30000],
        ]);

        $path = '/staff/ledger-reversals';
        $fundPath = "$path/member-fund/$fund";
        $payload = ['transaction_date' => '2026-11-20', 'reason' => 'Incorrect account coding'];
        $this->get($fundPath)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->post($fundPath, $payload)->assertForbidden();
        $this->actingAs($admin)->get($fundPath)->assertOk()->assertSee('Preview journal reversal')->assertSee('500.00');
        $this->assertDatabaseEmpty('ledger_journal_reversals');
        $this->from($fundPath)->post($fundPath, [...$payload, 'transaction_date' => '2026-11-09'])->assertSessionHasErrors('transaction_date');
        $this->post($fundPath, $payload)->assertRedirect();
        $reversal = DB::table('ledger_journal_reversals')->sole();
        $this->get("$path/{$reversal->id}")->assertOk()->assertSee($payload['reason'])->assertSee('DEPOSIT-001');
        $this->get("/staff/member-fund-journals/$transaction")->assertOk()->assertSee('View posted reversal');
        $this->from($fundPath)->post($fundPath, $payload)->assertSessionHasErrors('reason');
        $this->post("$path/loan/$posting", $payload)->assertRedirect();
        DB::statement('SET CONSTRAINTS ledger_reversal_balanced, ledger_reversal_lines_balanced IMMEDIATE');

        $this->assertDatabaseHas('member_fund_transactions', ['id' => $transaction, 'signed_minor' => 50000]);
        $this->assertDatabaseHas('loan_journal_lines', ['loan_journal_entry_id' => $entry, 'chart_account_id' => $bank, 'signed_minor' => -30000]);
        $this->assertDatabaseHas('ledger_journal_reversal_lines', ['ledger_journal_reversal_id' => $reversal->id, 'chart_account_id' => $bank, 'signed_minor' => -50000]);
        $this->assertDatabaseCount('ledger_journal_reversals', 2);

        $this->get('/staff/loan-ledger?from=2026-11-01&to=2026-11-19')->assertOk()
            ->assertViewHas('totals', fn (array $totals): bool => $totals['closing_debit'] === 20000);
        $this->get('/staff/loan-ledger?from=2026-11-01&to=2026-11-20')->assertOk()
            ->assertViewHas('totals', fn (array $totals): bool => $totals['closing_debit'] === 0 && $totals['closing_credit'] === 0);
        $this->get("/staff/loan-ledger/accounts/$bank")->assertOk()->assertSee('Journal reversal')->assertSee("$path/{$reversal->id}");
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
