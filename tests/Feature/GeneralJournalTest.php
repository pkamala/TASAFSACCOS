<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeneralJournalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_admin_can_post_and_reverse_a_split_expense_with_a_complete_audit_record(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(12, 0));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$expense, $bank, $liability] = $this->accounts();
        $payload = $this->entry($expense, $bank);
        $payload['lines'][1]['amount'] = '51.50';
        $payload['lines'][] = ['account' => $liability, 'side' => 'credit', 'amount' => '100.50'];

        $path = '/staff/general-journals';
        $this->get("$path/new")->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->post("$path/preview", $payload)->assertForbidden();
        $this->actingAs($admin)->get("$path/new")->assertOk()->assertSee('Office expenses');
        $this->post("$path/preview", $payload)->assertRedirect("$path/new#journal-preview");
        $token = session('general_journal_preview.token');
        $this->get("$path/new")->assertOk()->assertSee('Journal preview')->assertSee('152.00');
        $this->assertDatabaseEmpty('general_journals');

        $this->post("$path/post", ['token' => $token])->assertRedirect();
        DB::statement('SET CONSTRAINTS general_journal_balanced, general_journal_lines_balanced IMMEDIATE');
        $journal = DB::table('general_journals')->sole();
        $this->post("$path/post", ['token' => $token])->assertRedirect("$path/$journal->id");
        $this->assertDatabaseCount('general_journals', 1);
        $this->assertDatabaseHas('general_journals', ['source_reference' => 'INV-052', 'posted_by' => $admin->id, 'line_count' => 3]);
        $this->assertDatabaseHas('general_journal_lines', ['chart_account_id' => $expense, 'signed_minor' => 15200]);
        $this->assertDatabaseHas('general_journal_lines', ['chart_account_id' => $bank, 'signed_minor' => -5150]);
        $this->assertDatabaseHas('general_journal_lines', ['chart_account_id' => $liability, 'signed_minor' => -10050]);
        $this->get($path)->assertOk()->assertSee('INV-052');
        $this->get("$path/$journal->id")->assertOk()->assertSee('View or prepare reversal');
        $this->get("/staff/loan-ledger/accounts/$expense?from=2026-10-01&to=2026-11-22")->assertOk()->assertSee('INV-052')->assertViewHas('closingBalance', 15200);
        $this->get('/staff/ledger-financial-reports?from=2026-10-01&to=2026-11-22')->assertOk()
            ->assertViewHas('totals', fn (array $totals): bool => $totals['expenses'] === 15200 && $totals['difference'] === 0);

        $reversalPath = "/staff/ledger-reversals/general/$journal->id";
        $this->get($reversalPath)->assertOk()->assertSee('Preview journal reversal');
        $this->post($reversalPath, ['transaction_date' => '2026-11-22', 'reason' => 'Invoice cancelled'])->assertRedirect();
        $reversal = DB::table('ledger_journal_reversals')->sole();
        $this->assertDatabaseHas('ledger_journal_reversal_lines', ['ledger_journal_reversal_id' => $reversal->id, 'chart_account_id' => $expense, 'signed_minor' => -15200]);
        $this->get("/staff/ledger-reversals/$reversal->id")->assertOk()->assertSee('Invoice cancelled');
        $this->get("/staff/loan-ledger/accounts/$expense?from=2026-10-01&to=2026-11-22")->assertOk()->assertViewHas('closingBalance', 0);
        $this->assertDatabaseCount('general_journal_lines', 3);
    }

    public function test_unbalanced_lines_cannot_be_previewed_and_posting_checks_accounts_and_period_again(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(12, 0));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$expense, $bank] = $this->accounts();
        $payload = $this->entry($expense, $bank);
        $path = '/staff/general-journals';
        $this->actingAs($admin)->from("$path/new");
        $invalid = $payload;
        $invalid['lines'][0]['amount'] = '151.99';
        $this->post("$path/preview", $invalid)->assertSessionHasErrors('lines');
        $invalid['lines'][0]['amount'] = '1.999';
        $this->post("$path/preview", $invalid)->assertSessionHasErrors('lines.0.amount');

        $this->post("$path/preview", $payload)->assertRedirect();
        $this->post('/staff/ledger-periods', ['month' => '2026-10', 'action' => 'close', 'reason' => 'October reviewed'])->assertRedirect();
        $this->post("$path/post", ['token' => session('general_journal_preview.token')])->assertSessionHasErrors('transaction_date');

        $this->post("$path/preview", [...$payload, 'transaction_date' => '2026-11-22'])->assertRedirect();
        $this->post("/staff/accounts/$bank/retire", ['reason' => 'Bank account closed'])->assertRedirect();
        $this->post("$path/post", ['token' => session('general_journal_preview.token')])->assertSessionHasErrors('lines');
        $this->assertDatabaseEmpty('general_journals');
    }

    private function entry(int $expense, int $bank): array
    {
        return [
            'transaction_date' => '2026-10-31', 'category' => 'expense',
            'source_reference' => 'INV-052', 'description' => 'Office supplies',
            'lines' => [
                ['account' => $expense, 'side' => 'debit', 'amount' => '152'],
                ['account' => $bank, 'side' => 'credit', 'amount' => '152.00'],
            ],
        ];
    }

    private function accounts(): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];

        return array_map(function (int $id, string $category, string $name) use ($source): int {
            $categoryId = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => $id, 'name' => $category]);
            $typeId = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => $id, 'name' => $category, 'account_category_id' => $categoryId]);

            return DB::table('chart_accounts')->insertGetId([
                ...$source, 'legacy_id' => $id, 'account_type_id' => $typeId,
                'code' => (string) ($id * 1000), 'name' => $name, 'is_ledger_account' => true,
            ]);
        }, [1, 2, 3], ['Expense', 'Asset', 'Liability'], ['Office expenses', 'Bank current account', 'Trade payable']);
    }
}
