<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashBookTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cash_book_uses_designated_accounts_and_posted_movements_by_date(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 24));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$call, $wezesha, $clearing] = $this->accounts();
        $this->actingAs($admin)->patch(route('staff.chart-accounts.cash-book', $call), ['is_cash_book_account' => '1'])
            ->assertRedirect(route('staff.chart-accounts.show', $call));
        $this->assertDatabaseHas('cash_book_accounts', ['chart_account_id' => $call]);

        $opening = DB::table('ledger_opening_journals')->insertGetId([
            'effective_on' => '2026-11-01', 'review_reference' => 'Approved opening balances',
            'source_sha256' => str_repeat('b', 64), 'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        foreach ([$call => 50000, $clearing => -50000] as $account => $amount) {
            DB::table('ledger_opening_lines')->insert([
                'ledger_opening_journal_id' => $opening, 'chart_account_id' => $account, 'signed_minor' => $amount,
            ]);
        }
        $this->journal($admin, '2026-11-01', 'RECEIPT-1', [$call => 10000, $clearing => -10000]);
        $this->journal($admin, '2026-11-05', 'TRANSFER-1', [$wezesha => 3000, $call => -3000]);
        $this->journal($admin, '2026-11-21', 'OUTSIDE-PERIOD', [$wezesha => 9900, $clearing => -9900]);
        $dates = ['from' => '2026-11-01', 'to' => '2026-11-20'];

        $this->get(route('staff.cash-book.index', $dates))->assertOk()
            ->assertSee('NBC Call')->assertSee('NBC Wezesha')->assertDontSee('Clearing')
            ->assertSee(route('staff.loan-ledger.account', ['account' => $call, ...$dates]))
            ->assertViewHas('totals', [
                'opening_minor' => 50000, 'cutover_minor' => 0, 'receipts_minor' => 13000,
                'payments_minor' => 3000, 'closing_minor' => 60000,
            ]);
        $this->get(route('staff.loan-ledger.account', ['account' => $call, ...$dates]))->assertOk()
            ->assertSee('RECEIPT-1')->assertSee('TRANSFER-1')->assertSee('570.00 Dr');
        $export = $this->get(route('staff.cash-book.export', $dates))->assertOk()
            ->assertDownload('cash-book-2026-11-01-to-2026-11-20.csv');
        $this->assertStringContainsString(',,Total,500.00,0.00,130.00,30.00,600.00', $export->streamedContent());

        $this->get(route('staff.cash-book.index', ['from' => '2026-10-01', 'to' => '2026-11-01']))->assertOk()
            ->assertViewHas('totals', [
                'opening_minor' => 0, 'cutover_minor' => 50000, 'receipts_minor' => 10000,
                'payments_minor' => 0, 'closing_minor' => 60000,
            ]);
    }

    public function test_complete_imported_month_uses_workbook_totals_and_keeps_posted_ledger_available(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 24));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$call, , $clearing] = $this->accounts();
        DB::table('cash_book_accounts')->insert(['chart_account_id' => $call]);
        $this->journal($admin, '2026-10-15', 'POSTED-RECEIPT', [$call => 10000, $clearing => -10000]);
        DB::table('cash_book_monthly_balances')->insert([
            'month' => '2026-10-01', 'chart_account_id' => $call, 'opening_minor' => 50000,
            'receipts_minor' => 25000, 'payments_minor' => 10000, 'closing_minor' => 67000,
            'source_file' => 'Cashbook.xlsx', 'source_sha256' => str_repeat('c', 64),
        ]);
        $period = ['from' => '2026-10-01', 'to' => '2026-10-31'];

        $this->actingAs($admin)->get(route('staff.cash-book.index', $period))->assertOk()
            ->assertSee('Cashbook.xlsx')->assertSee('Workbook difference')->assertViewHas('totals', [
                'opening_minor' => 50000, 'receipts_minor' => 25000, 'payments_minor' => 10000,
                'difference_minor' => 2000, 'closing_minor' => 67000,
            ]);
        $this->get(route('staff.cash-book.index', [...$period, 'source' => 'ledger']))->assertOk()
            ->assertViewHas('totals', fn (array $totals): bool => $totals['closing_minor'] === 10000);
        $this->get(route('staff.cash-book.index', [...$period, 'to' => '2026-10-30']))->assertOk()
            ->assertViewHas('workbook', []);
        $this->get(route('staff.cash-book.index', [...$period, 'from' => '2026-09-01']))->assertOk()
            ->assertViewHas('workbook', []);

        $export = $this->get(route('staff.cash-book.export', $period))->assertOk()
            ->assertDownload('cash-book-workbook-2026-10-01-to-2026-10-31.csv');
        $this->assertStringContainsString(',,Total,500.00,250.00,100.00,20.00,670.00', $export->streamedContent());
    }

    public function test_cash_book_and_classification_require_administrator_access(): void
    {
        [$call] = $this->accounts();

        $this->get(route('staff.cash-book.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        $this->get(route('staff.cash-book.export'))->assertForbidden();
        $this->patch(route('staff.chart-accounts.cash-book', $call), ['is_cash_book_account' => '1'])->assertForbidden();
        $this->assertDatabaseMissing('cash_book_accounts', ['chart_account_id' => $call]);
    }

    public function test_an_administrator_can_record_a_past_cash_transaction_and_see_the_posted_payment(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 24));
        [, $wezesha, $clearing] = $this->accounts();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $path = '/staff/cash-book/transactions';
        $entry = [
            'transaction_date' => '2026-10-15', 'category' => 'expense',
            'source_reference' => 'OCT-VOUCHER-42', 'description' => 'October cash-book payment',
            'lines' => [
                ['account' => $clearing, 'side' => 'debit', 'amount' => '120.50'],
                ['account' => $wezesha, 'side' => 'credit', 'amount' => '120.50'],
            ],
        ];

        $this->actingAs($admin)->get("$path/new")->assertOk()
            ->assertSee('Record past cash-book transaction')->assertSee('NBC Wezesha');
        $this->from("$path/new")->post("$path/preview", $entry)->assertRedirect("$path/new#journal-preview");
        $token = session('cash_book_transaction_preview.token');
        $this->get("$path/new")->assertOk()->assertSee('OCT-VOUCHER-42');
        $this->assertDatabaseEmpty('general_journals');

        $this->post("$path/post", ['token' => $token])->assertRedirect();
        $journal = DB::table('general_journals')->sole();
        $this->post("$path/post", ['token' => $token])->assertRedirect("/staff/general-journals/$journal->id?cash_book=1");
        $this->assertDatabaseCount('general_journals', 1);
        $this->assertDatabaseHas('general_journals', ['id' => $journal->id, 'transaction_date' => '2026-10-15', 'source_reference' => 'OCT-VOUCHER-42']);
        $this->get("/staff/general-journals/$journal->id?cash_book=1")->assertOk()->assertSee('View cash book for this date');
        $this->get("/staff/general-journals/$journal->id/voucher")->assertOk()->assertSee('Expense voucher')->assertSee('OCT-VOUCHER-42');
        $this->get('/staff/cash-book?from=2026-10-15&to=2026-10-15')->assertOk()->assertViewHas('totals', [
            'opening_minor' => 0, 'cutover_minor' => 0, 'receipts_minor' => 0,
            'payments_minor' => 12050, 'closing_minor' => -12050,
        ]);
    }

    public function test_cash_book_entry_checks_account_classification_and_closed_periods_before_posting(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 24));
        [$call, $wezesha, $clearing] = $this->accounts();
        $this->actingAs(User::factory()->create(['is_staff_admin' => true]));
        $path = '/staff/cash-book/transactions';
        $entry = [
            'transaction_date' => '2026-10-15', 'category' => 'expense',
            'source_reference' => 'OCT-VOUCHER-43', 'description' => 'October payment',
            'lines' => [
                ['account' => $clearing, 'side' => 'debit', 'amount' => '100'],
                ['account' => $call, 'side' => 'credit', 'amount' => '100'],
            ],
        ];

        $this->from("$path/new")->post("$path/preview", $entry)->assertSessionHasErrors('lines');
        $entry['lines'][1]['account'] = $wezesha;
        $this->post("$path/preview", $entry)->assertRedirect("$path/new#journal-preview");
        $token = session('cash_book_transaction_preview.token');

        $this->patch(route('staff.chart-accounts.cash-book', $wezesha), ['is_cash_book_account' => '0'])->assertRedirect();
        $this->post("$path/post", ['token' => $token])->assertSessionHasErrors('lines');

        $this->patch(route('staff.chart-accounts.cash-book', $wezesha), ['is_cash_book_account' => '1'])->assertRedirect();
        $this->post('/staff/ledger-periods', ['month' => '2026-10', 'action' => 'close', 'reason' => 'Reviewed'])->assertRedirect();
        $this->post("$path/post", ['token' => $token])->assertSessionHasErrors('transaction_date');
        $this->assertDatabaseEmpty('general_journals');
    }

    private function accounts(): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Asset']);
        $type = DB::table('account_types')->insertGetId([
            ...$source, 'legacy_id' => 1, 'name' => 'Current assets', 'account_category_id' => $category,
        ]);

        $accounts = array_map(fn (int $id, string $name): int => DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => '1000',
            'name' => $name, 'is_ledger_account' => true,
        ]), [1, 2, 3], ['NBC Call', 'NBC Wezesha', 'Clearing']);
        DB::table('cash_book_accounts')->insert(['chart_account_id' => $accounts[1]]);

        return $accounts;
    }

    private function journal(User $admin, string $date, string $reference, array $lines): void
    {
        $journal = DB::table('general_journals')->insertGetId([
            'preview_token' => (string) Str::uuid(), 'transaction_date' => $date, 'category' => 'general',
            'source_reference' => $reference, 'description' => $reference, 'line_count' => count($lines),
            'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        foreach ($lines as $account => $amount) {
            DB::table('general_journal_lines')->insert([
                'general_journal_id' => $journal, 'chart_account_id' => $account, 'signed_minor' => $amount,
            ]);
        }
    }
}
