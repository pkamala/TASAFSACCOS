<?php

namespace Tests\Feature;

use App\Models\User;
use App\MonthlyStatementDrafts;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ActiveLoanMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const Path = '/staff/active-loans/migrate';

    public function test_verified_remaining_installments_flow_into_payroll_without_a_disbursement(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 24));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $reviewer = User::factory()->create(['board_role' => 'secretary']);
        [$member, $snapshot, $accounts] = $this->cutover($admin);
        $input = $this->schedule($snapshot, "7,2026-12-31,600.00,5.00\n7,2026-11-30,400.00,10.00\n");
        $this->actingAs($admin)->get(self::Path)->assertOk()->assertSee('Active loan migration');

        $this->post(self::Path.'/preview', $input)->assertRedirect(self::Path.'#preview');
        $this->get(self::Path)->assertOk()->assertSee('1,000.00')->assertSee('2026-11-30')->assertSee('Apply migration');
        $token = session('active_loan_migration_preview.token');
        $this->assertDatabaseEmpty('loan_applications');
        $this->post(self::Path.'/apply', ['token' => $token])->assertRedirect(self::Path)->assertSessionHasNoErrors();

        $loan = DB::table('loan_applications')->sole()->id;
        $this->assertDatabaseHas('active_loan_migrations', ['loan_application_id' => $loan, 'source_id' => 7, 'effective_on' => '2026-11-01']);
        $this->assertDatabaseHas('loan_application_installments', [
            'loan_application_id' => $loan, 'number' => 1, 'principal_minor' => 40000, 'interest_minor' => 1000,
            'opening_minor' => 100000, 'closing_minor' => 60000,
        ]);
        $this->assertDatabaseEmpty('loan_disbursements');
        $this->assertDatabaseEmpty('loan_journal_postings');
        $this->get(route('staff.loan-applications.show', [$member, $loan]))->assertOk()
            ->assertSee('Remaining repayment schedule at cutover')->assertSee('Signed migration schedule');
        $this->get(self::Path)->assertOk()->assertSee('Signed migration schedule');

        $this->post('/staff/loan-batches', ['kind' => 'repayment', 'period' => '2026-11'])->assertRedirect()->assertSessionHasNoErrors();
        $batch = DB::table('loan_batches')->sole();
        $items = json_decode($batch->items, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $items);
        $this->assertSame($loan, $items[0]['loan_id']);
        $this->assertSame($member, $items[0]['member_id']);
        $this->assertSame(41000, $items[0]['amount']);
        $this->assertSame(40000, $items[0]['allocations'][1]['principal']);
        $this->assertSame(1000, $items[0]['allocations'][1]['interest']);
        $url = "/staff/loan-batches/$batch->id";
        $this->actingAs($reviewer)->post("$url/review", ['decision' => 'approved', 'review_note' => 'Payroll checked'])->assertRedirect($url);
        $this->actingAs($admin)->post("$url/confirm", ['effective_date' => '2026-11-24', 'payroll_reference' => 'NOV-CUTOVER'])
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('loan_repayment_receipts', ['loan_application_id' => $loan, 'amount_minor' => 41000]);
        $this->assertDatabaseEmpty('loan_disbursements');

        $journals = "$url/journals";
        $this->post("$journals/preview", $accounts)->assertRedirect($journals)->assertSessionHasNoErrors();
        $this->post("$journals/post", ['token' => session('payroll_journal_preview.token')])
            ->assertRedirect($journals)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('loan_journal_entries', 1);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $accounts['principal'], 'signed_minor' => -40000]);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $accounts['cash'], 'signed_minor' => 41000]);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $accounts['offset'], 'signed_minor' => -1000]);
        $this->assertDatabaseEmpty('loan_disbursements');

        $statements = app(MonthlyStatementDrafts::class);
        $october = $statements->forMonth('2026-10-01', '2026-10-31')->sole();
        $november = $statements->forMonth('2026-11-01', '2026-11-30')->sole();
        $this->assertSame('1000.00', $statements->reviewRow($october)['loan_balance']);
        $this->assertSame('600.00', $statements->reviewRow($november)['loan_balance']);
        $this->assertNull($november->hold);

        $this->post(self::Path.'/preview', $input)->assertRedirect(self::Path.'#preview');
        $this->get(self::Path)->assertOk()->assertSee('Already migrated')->assertDontSee('Apply migration');
        $this->from(self::Path)->post(self::Path.'/apply', ['token' => session('active_loan_migration_preview.token')])
            ->assertSessionHasErrors('schedule');
        $this->assertDatabaseCount('loan_applications', 1);
    }

    public function test_migration_blocks_unverified_principal_and_a_payroll_list_created_after_preview(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 24));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [, $snapshot] = $this->cutover($admin);

        $this->get(self::Path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->post(self::Path.'/apply')->assertForbidden();
        $this->actingAs($admin)->from(self::Path)->post(self::Path.'/preview', $this->schedule($snapshot, "7,2026-10-31,1000,0\n"))
            ->assertSessionHasErrors('schedule');
        $this->post(self::Path.'/preview', $this->schedule($snapshot, "7,2026-11-30,999,0\n"))
            ->assertRedirect(self::Path.'#preview');
        $this->get(self::Path)->assertOk()->assertSee('Scheduled principal differs')->assertDontSee('Apply migration');

        $this->post(self::Path.'/preview', $this->schedule($snapshot, "7,2026-11-30,1000,0\n"))
            ->assertRedirect(self::Path.'#preview');
        DB::table('loan_batches')->insert([
            'kind' => 'repayment', 'period' => '2026-11-01', 'items' => '[]', 'status' => 'prepared',
            'prepared_by' => $admin->id, 'prepared_at' => now(),
        ]);
        $this->from(self::Path)->post(self::Path.'/apply', ['token' => session('active_loan_migration_preview.token')])
            ->assertSessionHasErrors(['schedule' => 'An affected payroll month already has a prepared, approved, or confirmed list.']);
        $this->assertDatabaseEmpty('loan_applications');
        $this->assertDatabaseEmpty('active_loan_migrations');
    }

    private function schedule(string $snapshot, string $rows): array
    {
        return [
            'snapshot' => $snapshot, 'reference' => 'Signed migration schedule',
            'schedule' => UploadedFile::fake()->createWithContent('schedule.csv', "legacy_loan_id,due_date,principal_tzs,interest_tzs\n".$rows),
        ];
    }

    private function cutover(User $admin): array
    {
        $snapshot = str_repeat('b', 64);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'member@example.com', 'status' => 'active',
            'legacy_member_id' => 53, 'legacy_source_sha256' => $snapshot,
        ]);
        DB::table('legacy_onboarding_rows')->insert([
            'source_sha256' => $snapshot, 'source_table' => 'loans', 'source_id' => 7,
            'data' => json_encode(['member_id' => '53', 'loan_code' => 'LOAN-7', 'disbursement_date' => '2026-08-01'], JSON_THROW_ON_ERROR),
            'issues' => '[]',
        ]);
        DB::table('verified_legacy_loan_balances')->insert([
            'source_sha256' => $snapshot, 'source_id' => 7, 'member_id' => $member, 'as_of' => '2026-10-31',
            'principal_minor' => 100000, 'evidence_reference' => 'October reconciliation',
            'report_sha256' => str_repeat('c', 64), 'verified_by' => $admin->id, 'verified_at' => now(),
        ]);
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-09-30', 'savings_minor' => 0, 'shares_minor' => 0,
            'evidence_reference' => 'Opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $opening = DB::table('ledger_opening_journals')->insertGetId([
            'effective_on' => '2026-11-01', 'review_reference' => 'Approved opening balances',
            'source_sha256' => str_repeat('d', 64), 'posted_by' => $admin->id, 'posted_at' => now(),
        ]);

        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Asset']);
        $type = DB::table('account_types')->insertGetId([
            ...$source, 'legacy_id' => 1, 'name' => 'Control', 'account_category_id' => $category,
        ]);
        $accounts = [];
        foreach (['cash' => 'Bank', 'principal' => 'Loans receivable', 'offset' => 'Interest income', 'equity' => 'Opening equity'] as $key => $name) {
            $id = count($accounts) + 1;
            $accounts[$key] = DB::table('chart_accounts')->insertGetId([
                ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => (string) $id, 'name' => $name, 'is_ledger_account' => true,
            ]);
        }
        foreach ([$accounts['principal'] => 100000, $accounts['equity'] => -100000] as $account => $amount) {
            DB::table('ledger_opening_lines')->insert([
                'ledger_opening_journal_id' => $opening, 'chart_account_id' => $account, 'signed_minor' => $amount,
            ]);
        }
        unset($accounts['equity']);

        return [$member, $snapshot, $accounts];
    }
}
