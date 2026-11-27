<?php

namespace Tests\Feature;

use App\JournalPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashBookReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_can_approve_and_post_a_workbook_journal_once(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 27));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$bank, $expense] = $this->accounts();
        $entry = $this->entry('CB2026-10-004');
        $page = route('staff.cash-book-review.show', $entry);
        $review = route('staff.cash-book-review.review', $entry);
        $post = route('staff.cash-book-review.post');
        $approval = [
            'decision' => 'approve', 'transaction_date' => '2026-10-02', 'accounts' => [$bank, $expense],
            'review_note' => 'Matched against bank advice and expense voucher.',
        ];

        $this->actingAs(User::factory()->create())->patch($review, $approval)->assertForbidden();
        $this->actingAs($admin)->get($page)->assertOk()->assertSee('Approve new journal')->assertSee('Bank charges');
        $this->from($page)->patch($review, [...$approval, 'accounts' => [$expense, $bank]])->assertSessionHasErrors('review');
        $this->patch($review, $approval)->assertRedirect($page);
        $this->assertDatabaseHas('cash_book_review_entries', ['id' => $entry, 'status' => 'approved', 'approved_date' => '2026-10-02']);
        $this->get($page)->assertOk()->assertSee('Approved posting');
        $this->get(route('staff.cash-book-review.index', ['month' => '2026-10']))->assertOk()->assertSee('Post 1 approved entries');
        $this->post($post, ['month' => '2026-09'])->assertRedirect();
        $this->assertDatabaseEmpty('general_journals');
        $this->post($post, ['month' => '2026-10'])->assertRedirect();
        $journal = DB::table('general_journals')->sole();
        $this->assertSame('CB2026-10-004', $journal->source_reference);
        $this->assertDatabaseHas('general_journal_lines', ['general_journal_id' => $journal->id, 'chart_account_id' => $bank, 'signed_minor' => -10000]);
        $this->assertDatabaseHas('general_journal_lines', ['general_journal_id' => $journal->id, 'chart_account_id' => $expense, 'signed_minor' => 10000]);
        $this->assertDatabaseHas('cash_book_review_entries', ['id' => $entry, 'status' => 'posted', 'general_journal_id' => $journal->id]);
        $this->post($post, ['month' => '2026-10'])->assertRedirect();
        $this->assertDatabaseCount('general_journals', 1);
    }

    public function test_staff_can_correct_a_proposal_and_post_only_the_selected_entry(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 27));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$bank, $expense] = $this->accounts();
        $entry = $this->entry('CB2026-10-004');
        $other = $this->entry('CB2026-10-005');
        $page = route('staff.cash-book-review.show', $entry);
        $review = route('staff.cash-book-review.review', $entry);
        $post = route('staff.cash-book-review.post-entry', $entry);
        $approval = [
            'decision' => 'approve', 'transaction_date' => '2026-10-02', 'accounts' => [$bank, $expense],
            'review_note' => 'Receipt confirms the corrected amount.',
        ];

        $this->actingAs($admin)->from($page)->patch($review, [
            'decision' => 'hold', 'review_note' => 'Obtain the bank receipt.',
        ])->assertRedirect($page);
        $this->assertDatabaseHas('cash_book_review_entries', ['id' => $entry, 'status' => 'pending', 'review_note' => 'Obtain the bank receipt.']);
        $this->post($post)->assertRedirect($page);
        $this->assertDatabaseEmpty('general_journals');
        $this->patch($review, [...$approval, 'amounts' => ['125.50', '125.00']])->assertSessionHasErrors('review');
        $this->patch($review, [...$approval, 'amounts' => ['125.50', '125.50']])->assertRedirect($page);
        $this->patch(route('staff.cash-book-review.review', $other), $approval)->assertRedirect();
        $this->get($page)->assertOk()->assertSee('Post this journal')->assertSee('125.50 TZS');
        $this->post($post)->assertRedirect($page);
        $this->post($post)->assertRedirect($page);
        $this->assertDatabaseCount('general_journals', 1);
        $this->assertDatabaseHas('general_journal_lines', ['chart_account_id' => $bank, 'signed_minor' => -12550]);
        $this->assertDatabaseHas('cash_book_review_entries', ['id' => $other, 'status' => 'approved']);
        $original = DB::table('cash_book_review_entries')->find($entry)->source_lines;
        $this->assertSame(-10000, json_decode($original, true)[0]['signed_minor']);
    }

    public function test_an_existing_journal_can_only_be_matched_to_one_source_row(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$bank, $expense] = $this->accounts();
        $journal = DB::transaction(fn (): int => app(JournalPosting::class)->writeGeneral($admin->id, (string) Str::uuid(), [
            'transaction_date' => '2026-10-01', 'category' => 'general', 'source_reference' => 'BANK-42', 'description' => 'Bank charges',
        ], [
            ['chart_account_id' => $bank, 'signed_minor' => -10000],
            ['chart_account_id' => $expense, 'signed_minor' => 10000],
        ]));
        $match = ['key' => "general:{$journal}", 'kind' => 'general', 'reference' => 'BANK-42'];
        $first = $this->entry('CB2026-10-004', [$match]);
        $second = $this->entry('CB2026-10-005', [$match]);
        $decision = ['decision' => 'match', 'match' => $match['key'], 'review_note' => 'Confirmed from the bank statement.'];

        $this->actingAs($admin)->patch(route('staff.cash-book-review.review', $first), $decision)->assertRedirect();
        $this->assertDatabaseHas('cash_book_review_entries', ['id' => $first, 'status' => 'matched', 'matched_journal' => $match['key']]);
        $this->patch(route('staff.cash-book-review.review', $second), $decision)->assertSessionHasErrors('review');
        $this->post(route('staff.cash-book-review.post'), ['month' => '2026-10'])->assertRedirect();
        $this->assertDatabaseCount('general_journals', 1);
    }

    public function test_trial_balance_preview_combines_opening_figures_and_reviewed_workbook_journals(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 27));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$bank, $expense] = $this->accounts();
        $entry = $this->entry('CB2026-10-004');
        $path = route('staff.cash-book-review.trial-balance');
        $opening = ['source_sha256' => str_repeat('a', 64), 'source_file' => 'Workbook.xlsx', 'as_of' => '2025-12-31'];
        DB::table('cash_book_review_opening_lines')->insert([
            [...$opening, 'source_row' => 5, 'account_code' => '1020', 'description' => 'Bank', 'signed_minor' => 50000],
            [...$opening, 'source_row' => 6, 'account_code' => null, 'description' => 'Unmapped liability', 'signed_minor' => -50001],
        ]);

        $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
        $this->actingAs($admin)->get($path)->assertOk()->assertSee('2025-12-31')->assertSee('Unmapped liability')
            ->assertSee('TZS 0.01 credit excess')
            ->assertSeeInOrder(['Account code', 'Description', '>DR</th>', '>CR</th>', 'Opening balances and journal movements'], false)
            ->assertViewHas('totals', fn (array $totals): bool => $totals['movement_debit'] === 0);
        $this->patch(route('staff.cash-book-review.review', $entry), [
            'decision' => 'approve', 'transaction_date' => '2026-10-02', 'accounts' => [$bank, $expense],
            'review_note' => 'Reviewed bank charge.',
        ])->assertRedirect();
        $this->get($path)->assertOk()->assertSee('1 approved and 0 posted')->assertViewHas('totals', [
            'opening_debit' => 50000, 'opening_credit' => 50001, 'movement_debit' => 10000,
            'movement_credit' => 10000, 'preview_debit' => 50000, 'preview_credit' => 50001,
        ]);
        $this->post(route('staff.cash-book-review.post'), ['month' => '2026-10'])->assertRedirect();
        $this->get($path)->assertOk()->assertSee('0 approved and 1 posted');
    }

    private function accounts(): array
    {
        $category = DB::table('account_categories')->insertGetId(['legacy_id' => 1, 'legacy_source_sha256' => str_repeat('a', 64), 'name' => 'Assets']);
        $type = DB::table('account_types')->insertGetId(['legacy_id' => 1, 'legacy_source_sha256' => str_repeat('a', 64), 'name' => 'Test', 'account_category_id' => $category]);
        $bank = DB::table('chart_accounts')->insertGetId(['code' => '1020', 'name' => 'Bank', 'account_type_id' => $type, 'is_ledger_account' => true]);
        $expense = DB::table('chart_accounts')->insertGetId(['code' => '5260', 'name' => 'Bank charges', 'account_type_id' => $type, 'is_ledger_account' => true]);
        DB::table('cash_book_accounts')->insert(['chart_account_id' => $bank, 'is_bank_account' => true]);

        return [$bank, $expense];
    }

    private function entry(string $key, array $matches = []): int
    {
        return DB::table('cash_book_review_entries')->insertGetId([
            'source_key' => $key, 'source_file' => 'Workbook.xlsx', 'source_sha256' => str_repeat('a', 64),
            'source_row' => 4, 'month' => '2026-10-01', 'transaction_date' => '2026-10-01',
            'description' => 'Bank charges', 'workflow' => 'General journal', 'match_candidates' => json_encode($matches),
            'source_lines' => json_encode([
                ['column' => 'G', 'label' => 'Bank', 'code' => '1020', 'bank_code' => '1020', 'signed_minor' => -10000],
                ['column' => 'H', 'label' => 'Bank charges', 'code' => '5260', 'bank_code' => null, 'signed_minor' => 10000],
            ]),
        ]);
    }
}
