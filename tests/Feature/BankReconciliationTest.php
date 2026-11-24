<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const Path = '/staff/bank-reconciliation';

    public function test_review_carries_outstanding_payments_forward_and_flags_backdated_postings(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2027, 1, 5));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$bank, $clearing] = $this->accounts($admin);
        $receipt = $this->journal($admin, $bank, $clearing, '2026-11-10', 20000, 'Deposit 1');
        $cheque = $this->journal($admin, $bank, $clearing, '2026-11-28', -15000, 'Cheque 1');
        $this->actingAs($admin);
        $november = self::Path."?month=2026-11&account=$bank";

        $response = $this->get($november)->assertOk()->assertSee('Awaiting review')->assertSee('Cheque 1');
        $data = [
            'account' => $bank, 'month' => '2026-11', 'basis' => $response->viewData('basis'),
            'statement' => '1200.00', 'statement_reference' => 'November bank statement',
            'cleared' => json_encode([$receipt], JSON_THROW_ON_ERROR),
        ];
        $this->from($november)->post(self::Path, $data)->assertRedirect($november)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bank_reconciliations', [
            'chart_account_id' => $bank, 'period' => '2026-11-01', 'book_minor' => 105000, 'statement_minor' => 120000,
            'outstanding_payments_minor' => 15000, 'outstanding_deposits_minor' => 0, 'reviewed_by' => $admin->id,
        ]);
        $this->get($november)->assertOk()->assertSee('Reconciled')->assertSee('November bank statement')->assertSee('1 entry');

        $december = self::Path."?month=2026-12&account=$bank";
        $deposit = $this->journal($admin, $bank, $clearing, '2026-12-30', 10000, 'Deposit in transit');
        $response = $this->get($december)->assertOk()->assertViewHas('selected', [$receipt => true])->assertSee('Deposit in transit');
        $this->post(self::Path, [
            ...$data, 'month' => '2026-12', 'basis' => $response->viewData('basis'), 'statement' => '1050',
            'statement_reference' => 'December bank statement', 'cleared' => json_encode([$receipt, $cheque], JSON_THROW_ON_ERROR),
        ])->assertRedirect($december)->assertSessionHasNoErrors();
        $record = DB::table('bank_reconciliations')->where('period', '2026-12-01')->sole();
        $this->assertSame(10000, $record->outstanding_deposits_minor);
        $this->assertSame(0, $record->outstanding_payments_minor);
        $this->assertSame((int) substr($deposit, strlen('general:')), json_decode($record->outstanding_lines, true, flags: JSON_THROW_ON_ERROR)[0]['entry_id']);
        $this->get($december)->assertOk()->assertSee('Reconciled');

        $this->journal($admin, $bank, $clearing, '2026-11-29', -500, 'Backdated bank charge');
        $this->get($november)->assertOk()->assertSee('Ledger changed');
        $this->get($december)->assertOk()->assertSee('Ledger changed');
        $this->from($november)->post(self::Path, $data)->assertSessionHasErrors('cleared');
        $this->assertDatabaseCount('bank_reconciliations', 2);
    }

    public function test_only_an_administrator_can_save_matching_entries_and_an_unresolved_difference_requires_a_note(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 12, 5));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$bank, $clearing] = $this->accounts($admin);
        $receipt = $this->journal($admin, $bank, $clearing, '2026-11-10', 20000, 'Deposit 1');

        $this->get(self::Path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->post(self::Path)->assertForbidden();
        $this->actingAs($admin);
        $url = self::Path."?month=2026-11&account=$bank";
        $basis = $this->get($url)->assertOk()->viewData('basis');
        $data = [
            'account' => $bank, 'month' => '2026-11', 'basis' => $basis, 'statement' => '1190',
            'statement_reference' => 'November bank statement', 'cleared' => json_encode([$receipt], JSON_THROW_ON_ERROR),
        ];

        $this->from($url)->post(self::Path, [...$data, 'cleared' => '["general:999"]'])->assertSessionHasErrors('cleared');
        $this->post(self::Path, $data)->assertSessionHasErrors('review_note');
        $this->assertDatabaseEmpty('bank_reconciliations');
        $this->post(self::Path, [...$data, 'review_note' => 'Investigate unmatched bank charge'])
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertSee('Difference to resolve')->assertSee('Investigate unmatched bank charge');
        $this->get('/staff/ledger-periods?month=2026-11')->assertOk()->assertSee('Review bank reconciliation status')->assertSee('0 of 1 bank accounts reconciled.');
        $this->assertDatabaseCount('bank_reconciliations', 1);
    }

    public function test_cutover_month_closes_only_with_a_current_zero_difference_review(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 12, 5));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$bank, $clearing] = $this->accounts($admin);
        $cheque = $this->journal($admin, $bank, $clearing, '2026-11-28', -15000, 'Outstanding cheque');
        $url = self::Path."?month=2026-11&account=$bank";
        $periods = '/staff/ledger-periods';
        $checklist = "$periods?month=2026-11";
        $close = ['month' => '2026-11', 'action' => 'close', 'reason' => 'Finance month-end review'];
        $blocker = 'Complete current bank reconciliations with zero unexplained differences before closing this month.';
        $this->actingAs($admin);

        $this->post($periods, [...$close, 'month' => '2026-10'])->assertRedirect("$periods?month=2026-10");
        $this->get($checklist)->assertOk()->assertSee($blocker)->assertDontSee('Close month');
        $this->from($checklist)->post($periods, $close)->assertSessionHasErrors(['action' => $blocker]);

        $review = [
            'account' => $bank, 'month' => '2026-11', 'basis' => $this->get($url)->viewData('basis'),
            'statement' => '990', 'statement_reference' => 'November statement',
            'review_note' => 'Investigate balance difference', 'cleared' => '[]',
        ];
        $this->post(self::Path, $review)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->get($checklist)->assertOk()->assertSee('difference to resolve')->assertDontSee('Close month');
        $this->post($periods, $close)->assertSessionHasErrors(['action' => $blocker]);

        $review['basis'] = $this->get($url)->viewData('basis');
        $review['statement'] = '1000';
        $review['review_note'] = 'Cheque remains outstanding';
        $this->post(self::Path, $review)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->get($checklist)->assertOk()->assertSee('Close month');

        $charge = $this->journal($admin, $bank, $clearing, '2026-11-30', -1000, 'Bank charge');
        $this->get($checklist)->assertOk()->assertSee('ledger changed')->assertDontSee('Close month');
        $this->post($periods, $close)->assertSessionHasErrors(['action' => $blocker]);
        $this->assertDatabaseMissing('ledger_period_events', ['period' => '2026-11-01', 'action' => 'closed']);

        $review['basis'] = $this->get($url)->viewData('basis');
        $review['statement'] = '990';
        $review['cleared'] = json_encode([$charge], JSON_THROW_ON_ERROR);
        $this->post(self::Path, $review)->assertRedirect($url)->assertSessionHasNoErrors();
        $latest = DB::table('bank_reconciliations')->latest('id')->first();
        $this->assertSame(15000, $latest->outstanding_payments_minor);
        $this->assertSame((int) substr($cheque, strlen('general:')), json_decode($latest->outstanding_lines, true, flags: JSON_THROW_ON_ERROR)[0]['entry_id']);
        $this->post($periods, $close)->assertRedirect($checklist)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ledger_period_events', ['period' => '2026-11-01', 'action' => 'closed']);

        $closed = $this->get($url)->assertOk()->assertSee('The ledger month is closed.')->assertDontSee('Save review');
        $this->from($url)->post(self::Path, [...$review, 'basis' => $closed->viewData('basis')])->assertSessionHasErrors('month');
        $this->post($periods, [...$close, 'action' => 'reopen'])->assertRedirect($checklist);
        $this->get($url)->assertOk()->assertSee('Save review');
    }

    private function accounts(User $admin): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Asset']);
        $type = DB::table('account_types')->insertGetId([
            ...$source, 'legacy_id' => 1, 'name' => 'Current assets', 'account_category_id' => $category,
        ]);
        $accounts = array_map(fn (int $id, string $name): int => DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => '1000', 'name' => $name, 'is_ledger_account' => true,
        ]), [1, 2], ['Bank', 'Clearing']);
        DB::table('cash_book_accounts')->insert(['chart_account_id' => $accounts[0], 'is_bank_account' => true]);
        $opening = DB::table('ledger_opening_journals')->insertGetId([
            'effective_on' => '2026-11-01', 'review_reference' => 'Opening balances',
            'source_sha256' => str_repeat('b', 64), 'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        foreach ([$accounts[0] => 100000, $accounts[1] => -100000] as $account => $amount) {
            DB::table('ledger_opening_lines')->insert([
                'ledger_opening_journal_id' => $opening, 'chart_account_id' => $account, 'signed_minor' => $amount,
            ]);
        }

        return $accounts;
    }

    private function journal(User $admin, int $bank, int $clearing, string $date, int $amount, string $reference): string
    {
        $journal = DB::table('general_journals')->insertGetId([
            'preview_token' => (string) Str::uuid(), 'transaction_date' => $date, 'category' => 'general',
            'source_reference' => $reference, 'description' => $reference, 'line_count' => 2,
            'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        $bankLine = DB::table('general_journal_lines')->insertGetId([
            'general_journal_id' => $journal, 'chart_account_id' => $bank, 'signed_minor' => $amount,
        ]);
        DB::table('general_journal_lines')->insert([
            'general_journal_id' => $journal, 'chart_account_id' => $clearing, 'signed_minor' => -$amount,
        ]);

        return 'general:'.$bankLine;
    }
}
