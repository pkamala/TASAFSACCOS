<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerOpeningJournalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_balanced_opening_journal_posts_once_with_an_audited_cutover_date(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $this->accounts();
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'member@example.com', 'status' => 'active',
        ]);
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-09-30', 'savings_minor' => 0, 'shares_minor' => 0,
            'evidence_reference' => 'Opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);

        $path = '/staff/ledger-opening';
        $header = "legacy_account_id,account_code,debit_tzs,credit_tzs\n";
        $valid = "1,1110,500.25,\n2,1110,,500.25\n";
        $file = fn (string $rows): UploadedFile => UploadedFile::fake()->createWithContent('opening.csv', $header.$rows);
        $form = ['effective_on' => '2026-11-01', 'review_reference' => 'October approved trial balance'];

        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->post("$path/post")->assertForbidden();
        $this->actingAs($admin)->get("$path/template")->assertDownload('ledger_opening_journal.csv');
        $this->from($path)->post("$path/preview", [...$form, 'opening_journal' => $file("1,1110,500.25,\n2,1110,,500.00\n")])
            ->assertSessionHasErrors('opening_journal');
        $this->post("$path/preview", [...$form, 'opening_journal' => $file(str_replace('2,1110', '2,2220', $valid))])
            ->assertRedirect("$path#opening-lines");
        $this->get($path)->assertOk()->assertSee('Account code does not match')->assertDontSee('Post opening journal');
        $this->from($path)->post("$path/post", ['token' => session('ledger_opening_preview.token')])->assertSessionHasErrors('opening_journal');

        $this->post("$path/preview", [...$form, 'opening_journal' => $file($valid)])->assertRedirect("$path#opening-lines");
        $token = session('ledger_opening_preview.token');
        $this->assertDatabaseEmpty('ledger_opening_journals');
        $this->post("$path/post", ['token' => $token])->assertRedirect($path);
        DB::statement('SET CONSTRAINTS ledger_opening_balanced, ledger_opening_lines_balanced IMMEDIATE');
        $this->assertDatabaseHas('ledger_opening_journals', [
            'effective_on' => '2026-11-01', 'review_reference' => $form['review_reference'],
            'source_sha256' => hash('sha256', $header.$valid), 'posted_by' => $admin->id,
        ]);
        $this->assertDatabaseCount('ledger_opening_lines', 2);
        $this->get($path)->assertOk()->assertSee('Posted opening balances')->assertSee('Print opening journal');
        $this->from($path)->post("$path/preview", [...$form, 'opening_journal' => $file($valid)])->assertSessionHasErrors('opening_journal');
        $this->assertDatabaseCount('ledger_opening_journals', 1);

        $this->get('/staff/loan-ledger?from=2026-11-01&to=2026-11-01')->assertOk()
            ->assertViewHas('totals', [
                'opening_debit' => 50025, 'opening_credit' => 50025, 'debit' => 0, 'credit' => 0,
                'closing_debit' => 50025, 'closing_credit' => 50025,
            ]);
        $this->get('/staff/loan-ledger?from=2026-10-31&to=2026-10-31')->assertOk()->assertSee('No posted journal activity');

        $funds = "/staff/members/$member/funds";
        $bank = DB::table('chart_accounts')->where('legacy_id', 1)->value('id');
        DB::table('cash_book_accounts')->insertOrIgnore(['chart_account_id' => $bank]);
        $deposit = ['receiving_account_id' => $bank, 'kind' => 'savings_deposit', 'amount' => '25', 'reference' => 'NEW-001', 'effective_on' => '2026-10-31'];
        $this->from($funds)->post($funds, $deposit)->assertSessionHasErrors('effective_on');
        $this->post($funds, [...$deposit, 'effective_on' => '2026-11-01'])->assertRedirect($funds);
        $this->assertDatabaseHas('member_fund_transactions', ['reference' => 'NEW-001', 'effective_on' => '2026-11-01']);
    }

    private function accounts(): void
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Category']);
        $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Type', 'account_category_id' => $category]);
        foreach ([1 => 'Bank', 2 => 'Opening equity'] as $id => $name) {
            DB::table('chart_accounts')->insert([
                ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => '1110', 'name' => $name, 'is_ledger_account' => true,
            ]);
        }
    }
}
