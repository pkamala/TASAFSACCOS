<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CutoverChecklistTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cutover_checklist_compares_posted_verified_and_migrated_principal(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$receivable, $offset] = $this->accounts();
        $journal = DB::table('ledger_opening_journals')->insertGetId([
            'effective_on' => '2026-11-01', 'review_reference' => 'October approved trial balance',
            'source_sha256' => str_repeat('a', 64), 'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        foreach ([$receivable => 100000, $offset => -100000] as $id => $amount) {
            DB::table('ledger_opening_lines')->insert([
                'ledger_opening_journal_id' => $journal, 'chart_account_id' => $id, 'signed_minor' => $amount,
            ]);
        }
        $snapshot = str_repeat('b', 64);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415', 'email' => 'asha@example.com',
            'status' => 'active', 'legacy_member_id' => 53, 'legacy_source_sha256' => $snapshot,
        ]);
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-10-31', 'savings_minor' => 20000, 'shares_minor' => 5000,
            'evidence_reference' => 'Member statement', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        foreach ([7 => '2026-10-01', 8 => '2026-10-02', 9 => '2026-10-03', 10 => '2026-11-03'] as $id => $disbursed) {
            DB::table('legacy_onboarding_rows')->insert([
                'source_sha256' => $snapshot, 'source_table' => 'loans', 'source_id' => $id, 'issues' => '[]',
                'data' => json_encode(['member_id' => '53', 'loan_code' => "LOAN-$id", 'disbursement_date' => $disbursed], JSON_THROW_ON_ERROR),
            ]);
        }
        $balances = [];
        foreach ([7 => 60000, 8 => 40000] as $id => $amount) {
            $balances[$id] = DB::table('verified_legacy_loan_balances')->insertGetId([
                'source_sha256' => $snapshot, 'source_id' => $id, 'member_id' => $member, 'as_of' => '2026-10-31',
                'principal_minor' => $amount, 'evidence_reference' => 'Loan reconciliation',
                'report_sha256' => str_repeat('c', 64), 'verified_by' => $admin->id, 'verified_at' => now(),
            ]);
        }
        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => Str::uuid()->toString(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 60000, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 0, 'term_months' => 1, 'interest_method' => 'scheduled',
            'first_due_date' => '2026-11-30', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        DB::table('active_loan_migrations')->insert([
            'loan_application_id' => $loan, 'verified_balance_id' => $balances[7], 'source_sha256' => $snapshot,
            'source_id' => 7, 'effective_on' => '2026-11-01', 'schedule_sha256' => str_repeat('d', 64), 'review_reference' => 'Approved',
        ]);

        $path = route('staff.cutover-checklist');
        $report = $this->actingAs($admin)->get(route('staff.cutover-checklist', ['receivables' => [$receivable]]));
        $report->assertOk()->assertViewHas('receivableMinor', 100000)
            ->assertSee('October approved trial balance')->assertSee('1 of 1 active members')
            ->assertSee('LOAN-8')->assertSee('LOAN-9')->assertDontSee('LOAN-7')->assertDontSee('LOAN-10');
        $totals = $report->viewData('totals');
        $this->assertSame(100000, (int) $totals->verified_minor);
        $this->assertSame(60000, (int) $totals->migrated_minor);
        $this->assertSame(1, (int) $totals->pending_loans);
        $this->assertSame(1, (int) $totals->unverified_loans);
        $this->from($path)->get(route('staff.cutover-checklist', ['receivables' => [99999]]))->assertSessionHasErrors('receivables');
    }

    public function test_checklist_requires_an_administrator_and_guides_opening_journal_setup(): void
    {
        $path = route('staff.cutover-checklist');
        $this->get($path)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_staff_admin' => true]))->get($path)
            ->assertOk()->assertSee('Post the approved opening trial balance')->assertDontSee('Compare balances');
    }

    private function accounts(): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Asset']);
        $type = DB::table('account_types')->insertGetId([
            ...$source, 'legacy_id' => 1, 'name' => 'Opening balances', 'account_category_id' => $category,
        ]);

        return array_map(fn (int $id, string $name): int => DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => (string) $id, 'name' => $name, 'is_ledger_account' => true,
        ]), [1, 2], ['Loans receivable', 'Opening offset']);
    }
}
