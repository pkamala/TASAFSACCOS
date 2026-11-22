<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoanJournalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_confirmed_lists_can_be_previewed_and_posted_once_with_balanced_lines(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10074',
            'email' => 'member@example.com', 'status' => 'active',
        ]);
        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 100000, 'insurance_minor' => 1000, 'processing_fee_minor' => 2000, 'other_charges_minor' => 0,
            'annual_basis_points' => 1000, 'term_months' => 1, 'interest_method' => 'flat',
            'first_due_date' => '2026-11-30', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        $disbursement = $this->batch('disbursement', $admin->id, $loan, $member, 100000);
        $repayment = $this->batch('repayment', $admin->id, $loan, $member, 11000);
        DB::table('loan_disbursements')->insert([
            'loan_application_id' => $loan, 'transfer_reference' => 'BANK-1', 'cash_amount_minor' => 100000, 'transferred_on' => '2026-11-22',
            'loan_batch_id' => $disbursement, 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $receipt = DB::table('loan_repayment_receipts')->insertGetId([
            'loan_application_id' => $loan, 'receipt_reference' => 'PAYROLL-1', 'received_on' => '2026-11-22',
            'amount_minor' => 11000, 'loan_batch_id' => $repayment, 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('loan_application_installments')->insert([
            'loan_application_id' => $loan, 'number' => 1, 'due_date' => '2026-11-30', 'opening_minor' => 103000,
            'principal_minor' => 103000, 'interest_minor' => 1100, 'payment_minor' => 104100, 'closing_minor' => 0,
        ]);
        DB::table('loan_repayment_allocations')->insert([
            'loan_application_id' => $loan, 'loan_repayment_receipt_id' => $receipt, 'installment_number' => 1,
            'principal_minor' => 10000, 'interest_minor' => 1000,
        ]);

        [$bank, $receivable, $clearing, $interest] = $this->accounts();
        $path = '/staff/loan-journal';
        $this->get("$path/$disbursement")->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get("$path/$disbursement")->assertForbidden();

        $this->actingAs($admin)->from("$path/$disbursement")->post("$path/$disbursement/post", ['token' => str_repeat('a', 40)])
            ->assertSessionHasErrors('journal');
        $this->post("$path/$disbursement/preview", ['cash_account' => $bank, 'principal_account' => $receivable])
            ->assertSessionHasErrors('journal');
        $this->post("$path/$disbursement/preview", [
            'cash_account' => $bank, 'principal_account' => $receivable, 'offset_account' => $clearing,
        ])->assertRedirect("$path/$disbursement");
        $token = session('loan_journal_preview.token');
        $this->assertDatabaseEmpty('loan_journal_postings');
        $this->withSession(['loan_journal_preview' => json_decode(
            json_encode(session('loan_journal_preview'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR,
        )]);
        $this->get("$path/$disbursement")->assertOk()->assertSee('Preview journal lines')->assertSee('1,030.00');
        $this->post("$path/$disbursement/post", ['token' => $token])->assertRedirect("$path/$disbursement");
        $this->from("$path/$disbursement")->post("$path/$disbursement/post", ['token' => $token])->assertSessionHasErrors('journal');

        $this->post("$path/$repayment/preview", [
            'cash_account' => $bank, 'principal_account' => $receivable, 'offset_account' => $interest,
        ])->assertRedirect("$path/$repayment");
        $this->post("$path/$repayment/post", ['token' => session('loan_journal_preview.token')])->assertRedirect("$path/$repayment");
        $this->get("$path/$repayment")->assertOk()->assertSee('PAYROLL-1')->assertSee('Print journal');

        DB::statement('SET CONSTRAINTS loan_journal_entry_balanced, loan_journal_lines_balanced IMMEDIATE');
        $this->assertDatabaseCount('loan_journal_postings', 2);
        $this->assertDatabaseCount('loan_journal_entries', 2);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $receivable, 'signed_minor' => 103000]);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $clearing, 'signed_minor' => -3000]);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $interest, 'signed_minor' => -1000]);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $bank, 'signed_minor' => 11000]);
    }

    private function batch(string $kind, int $actor, int $loan, int $member, int $amount): int
    {
        return DB::table('loan_batches')->insertGetId([
            'kind' => $kind, 'items' => json_encode([['loan_id' => $loan, 'member_id' => $member, 'amount' => $amount]], JSON_THROW_ON_ERROR),
            'status' => 'confirmed', 'prepared_by' => $actor, 'prepared_at' => now(), 'effective_date' => '2026-11-22',
        ]);
    }

    private function accounts(): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Test category']);
        $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Test type', 'account_category_id' => $category]);
        $ids = [];
        foreach (['Bank', 'Receivable', 'Clearing', 'Interest'] as $index => $name) {
            $ids[] = DB::table('chart_accounts')->insertGetId([
                ...$source, 'legacy_id' => $index + 1, 'account_type_id' => $type, 'code' => (string) ($index + 1), 'name' => $name, 'is_ledger_account' => true,
            ]);
        }

        return $ids;
    }
}
