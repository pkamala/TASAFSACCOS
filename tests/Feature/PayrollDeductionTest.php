<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayrollDeductionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reviewed_partial_payroll_posts_actual_amounts_and_can_carry_unpaid_installments_forward(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 24));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$member, $loan, $batch] = $this->approvedPayroll($admin);
        $url = "/staff/loan-batches/$batch";
        $review = "$url/deductions";

        $this->actingAs(User::factory()->create(['board_role' => 'chairperson']))->get($review)->assertForbidden();
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Review actual payroll deductions');
        $template = $this->get("$review/template")->assertDownload("payroll-$batch-deductions.csv");
        $this->assertStringContainsString("loan:$loan:1:principal,10415,100.00,", $template->streamedContent());

        $report = $this->report($loan, $member, principal: '70.00', interest: '0', savings: '5.00');
        $this->post("$review/preview", ['deductions' => $report])->assertRedirect("$review#deduction-preview")->assertSessionHasNoErrors();
        $token = session('payroll_deduction_preview.token');
        $this->get($review)->assertOk()->assertSee('Partial')->assertSee('Missed')->assertSee('75.00');
        $confirmation = [
            'effective_date' => '2026-11-24', 'payroll_reference' => 'NOV-ACTUAL',
            'deduction_token' => $token,
        ];
        $this->from($review)->post("$url/confirm", $confirmation)->assertSessionHasErrors('deduction_note');
        $this->assertDatabaseEmpty('loan_repayment_receipts');

        $this->post("$url/confirm", [...$confirmation, 'deduction_note' => 'Short deductions verified'])
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('loan_repayment_receipts', ['loan_application_id' => $loan, 'amount_minor' => 7000]);
        $this->assertDatabaseHas('loan_repayment_allocations', ['loan_application_id' => $loan, 'principal_minor' => 7000, 'interest_minor' => 0]);
        $this->assertDatabaseHas('member_fund_transactions', ['loan_batch_id' => $batch, 'kind' => 'savings_deposit', 'signed_minor' => 500]);
        $this->assertDatabaseMissing('member_fund_transactions', ['loan_batch_id' => $batch, 'kind' => 'share_purchase']);
        $this->get($url)->assertOk()->assertSee('Confirmed payroll deductions')->assertSee('Short deductions verified')
            ->assertSee('Planned amount (TZS)')->assertSee('Partial')->assertSee('Missed');
        $saved = DB::table('loan_batches')->find($batch);
        $this->assertSame(13000, array_sum(array_column(json_decode($saved->items, true, flags: JSON_THROW_ON_ERROR), 'amount')));
        $this->assertSame('NOV-ACTUAL', json_decode($saved->deduction_review, true, flags: JSON_THROW_ON_ERROR)['reference']);

        $accounts = $this->accounts();
        $this->post("$url/journals/preview", $accounts)->assertRedirect("$url/journals")->assertSessionHasNoErrors();
        $this->post("$url/journals/post", ['token' => session('payroll_journal_preview.token')])
            ->assertRedirect("$url/journals")->assertSessionHasNoErrors();
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $accounts['principal'], 'signed_minor' => -7000]);
        $this->assertDatabaseHas('member_fund_journals', ['amount_minor' => 500]);

        $next = '/staff/loan-batches';
        $this->from("$next/new")->post($next, ['kind' => 'repayment', 'period' => '2026-12'])->assertSessionHasErrors('batch');
        $this->post($next, ['kind' => 'repayment', 'period' => '2026-12', 'include_overdue' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $item = json_decode(DB::table('loan_batches')->where('period', '2026-12-01')->sole()->items, true, flags: JSON_THROW_ON_ERROR)[0];
        $this->assertSame(4000, $item['amount']);
        $this->assertSame(3000, $item['allocations'][1]['principal']);
        $this->assertSame(1000, $item['allocations'][1]['interest']);
        $this->assertSame('2026-11-30', $item['overdue_installments'][1]);
    }

    public function test_excess_deductions_block_confirmation_and_an_all_missed_payroll_creates_no_receipts(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 24));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        [$member, $loan, $batch] = $this->approvedPayroll($admin);
        $url = "/staff/loan-batches/$batch";
        $review = "$url/deductions";
        $this->actingAs($admin)->from($review);
        $incomplete = UploadedFile::fake()->createWithContent('actual.csv', "deduction_key,registration_no,planned_tzs,actual_tzs\nloan:$loan:1:principal,10415,100,100\n");
        $this->post("$review/preview", ['deductions' => $incomplete])->assertSessionHasErrors('deductions');

        $this->post("$review/preview", ['deductions' => $this->report($loan, $member, '101', '10', '15')])->assertRedirect("$review#deduction-preview");
        $this->get($review)->assertOk()->assertSee('Excess')->assertDontSee('Confirm payroll deductions');
        $confirmation = [
            'effective_date' => '2026-11-24', 'payroll_reference' => 'NOV-REVIEW',
            'deduction_note' => 'TASAF Accounts confirmed missed deductions',
        ];
        $this->post("$url/confirm", [...$confirmation, 'deduction_token' => session('payroll_deduction_preview.token')])
            ->assertSessionHasErrors('deductions');
        $this->assertDatabaseHas('loan_batches', ['id' => $batch, 'status' => 'approved']);

        $this->post("$review/preview", ['deductions' => $this->report($loan, $member, '0', '0', '0')])->assertRedirect("$review#deduction-preview");
        $this->post("$url/confirm", [...$confirmation, 'deduction_token' => session('payroll_deduction_preview.token')])
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertSee('There are no payment journals to post.');
        $this->assertDatabaseEmpty('loan_repayment_receipts');
        $this->assertDatabaseEmpty('member_fund_transactions');
        $this->assertDatabaseEmpty('loan_journal_postings');
    }

    private function report(int $loan, int $member, string $principal, string $interest, string $savings): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('actual.csv', implode("\n", [
            'deduction_key,registration_no,planned_tzs,actual_tzs',
            "loan:$loan:1:principal,10415,100.00,$principal",
            "loan:$loan:1:interest,10415,10.00,$interest",
            "fund:$member:savings_deposit,10415,15.00,$savings",
            "fund:$member:share_purchase,10415,5.00,0",
            '',
        ]));
    }

    private function approvedPayroll(User $admin): array
    {
        $reviewer = User::factory()->create(['board_role' => 'secretary']);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415', 'email' => 'asha@example.com', 'status' => 'active',
        ]);
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-10-31', 'savings_minor' => 0, 'shares_minor' => 0,
            'evidence_reference' => 'Opening balances', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => Str::uuid()->toString(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 10000, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 0, 'term_months' => 1, 'interest_method' => 'flat',
            'first_due_date' => '2026-11-30', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        DB::table('loan_disbursements')->insert([
            'loan_application_id' => $loan, 'transfer_reference' => 'TRANSFER', 'cash_amount_minor' => 10000,
            'transferred_on' => '2026-11-01', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('loan_application_installments')->insert([
            'loan_application_id' => $loan, 'number' => 1, 'due_date' => '2026-11-30', 'opening_minor' => 10000,
            'principal_minor' => 10000, 'interest_minor' => 1000, 'payment_minor' => 11000, 'closing_minor' => 0,
        ]);
        $batch = DB::table('loan_batches')->insertGetId([
            'kind' => 'repayment', 'period' => '2026-11-01', 'status' => 'approved',
            'prepared_by' => $admin->id, 'prepared_at' => now(), 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
            'items' => json_encode([
                ['loan_id' => $loan, 'member_id' => $member, 'amount' => 11000, 'allocations' => [1 => ['principal' => 10000, 'interest' => 1000]]],
                ['member_id' => $member, 'amount' => 2000, 'funds' => ['savings_deposit' => 1500, 'share_purchase' => 500]],
            ], JSON_THROW_ON_ERROR),
        ]);

        return [$member, $loan, $batch];
    }

    private function accounts(): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Accounts']);
        $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Ledger', 'account_category_id' => $category]);
        $accounts = [];
        foreach (['cash' => 'Bank', 'principal' => 'Loans receivable', 'savings_deposit' => 'Savings payable'] as $key => $name) {
            $id = count($accounts) + 1;
            $accounts[$key] = DB::table('chart_accounts')->insertGetId([
                ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => (string) $id, 'name' => $name, 'is_ledger_account' => true,
            ]);
        }

        return $accounts;
    }
}
