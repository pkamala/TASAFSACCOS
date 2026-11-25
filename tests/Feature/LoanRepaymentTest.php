<?php

namespace Tests\Feature;

use App\MemberFunds;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoanRepaymentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_confirmed_receipts_update_the_loan_only_when_allocations_balance_and_fit_the_schedule(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(14, 0));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Mushi', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active',
        ]);
        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 10000, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 1200, 'term_months' => 2, 'interest_method' => 'flat',
            'first_due_date' => '2026-11-21', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        DB::table('loan_disbursements')->insert([
            'loan_application_id' => $loan, 'transfer_reference' => 'TR-1', 'cash_amount_minor' => 10000,
            'transferred_on' => '2026-11-15', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        foreach ([1, 2] as $number) {
            DB::table('loan_application_installments')->insert([
                'loan_application_id' => $loan, 'number' => $number, 'due_date' => $number === 1 ? '2026-11-21' : '2026-12-21',
                'opening_minor' => 15000 - 5000 * $number, 'principal_minor' => 5000,
                'interest_minor' => 100, 'payment_minor' => 5100, 'closing_minor' => 10000 - 5000 * $number,
            ]);
        }

        $path = "/staff/members/$member/loan-applications/$loan";
        $list = '/staff/loan-batches';
        $chair = User::factory()->create(['board_role' => 'chairperson']);
        $payload = [
            'kind' => 'repayment', 'period' => '2026-11',
        ];

        $this->actingAs($admin)->post("$path/repayments", [])->assertNotFound();
        $this->post($list, $payload)->assertRedirect();
        $batch = DB::table('loan_batches')->sole()->id;
        $batchUrl = "$list/$batch";
        $confirmation = ['effective_date' => '2026-11-22', 'payroll_reference' => 'payroll-1'];
        $this->from($batchUrl)->post("$batchUrl/confirm", $confirmation)->assertSessionHasErrors('batch');
        $this->assertDatabaseEmpty('loan_repayment_receipts');
        $this->actingAs($chair)->post("$batchUrl/review", ['decision' => 'approved', 'review_note' => 'Payroll reviewed'])->assertRedirect($batchUrl);
        $this->assertDatabaseEmpty('loan_repayment_receipts');
        $this->actingAs($admin)->post("$batchUrl/confirm", $confirmation)->assertRedirect($batchUrl);
        $this->assertDatabaseHas('loan_applications', ['id' => $loan, 'status' => 'disbursed']);
        $this->assertDatabaseHas('loan_repayment_receipts', ['loan_batch_id' => $batch, 'loan_application_id' => $loan, 'amount_minor' => 5100]);
        $this->assertDatabaseCount('loan_repayment_allocations', 1);
        $this->from("$list/new")->post($list, $payload)->assertSessionHasErrors('batch');

        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'as_of' => '2026-11-15', 'savings_minor' => 3000, 'shares_minor' => 0,
            'evidence_reference' => 'Verified opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('member_savings_withdrawals')->insert([
            'member_id' => $member, 'amount_minor' => 1800, 'status' => 'approved',
            'requested_by' => $admin->id, 'reason' => 'Approved payout', 'requested_at' => now(),
        ]);
        $payment = ['reference' => 'savings-1', 'effective_on' => '2026-11-22', 'amount' => '10'];
        $this->actingAs($chair)->post("$path/savings-payment", $payment)->assertForbidden();
        $this->actingAs($admin)->get($path)->assertOk()->assertSee('Repay from savings');
        $this->from($path)->post("$path/savings-payment", [...$payment, 'amount' => '13'])->assertSessionHasErrors('amount');
        $this->assertDatabaseEmpty('member_fund_transactions');
        $this->post("$path/savings-payment", $payment)->assertSessionHasNoErrors()->assertRedirect();
        $savingsBatch = DB::table('loan_batches')->where('kind', 'savings_payment')->sole()->id;
        $journal = "/staff/loan-journal/$savingsBatch";
        $this->post("$path/savings-payment", $payment)->assertRedirect($journal);
        $this->assertDatabaseCount('member_savings_withdrawals', 1);
        $this->assertDatabaseCount('member_fund_transactions', 1);
        $this->assertSame(200, app(MemberFunds::class)->balances($member)['available']);
        $this->assertDatabaseHas('loan_repayment_allocations', [
            'loan_application_id' => $loan, 'installment_number' => 2, 'principal_minor' => 900, 'interest_minor' => 100,
        ]);
        $this->get($journal)->assertOk()->assertSee('Member savings account');
        $this->get("$list/$savingsBatch")->assertOk()->assertSee('Savings-funded repayment');

        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Category']);
        $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Type', 'account_category_id' => $category]);
        foreach (['Member savings', 'Loan principal', 'Interest'] as $index => $name) {
            $accounts[] = DB::table('chart_accounts')->insertGetId([
                ...$source, 'legacy_id' => $index + 1, 'account_type_id' => $type, 'code' => (string) ($index + 1),
                'name' => $name, 'is_ledger_account' => true,
            ]);
        }
        $this->post("$journal/preview", [
            'cash_account' => $accounts[0], 'principal_account' => $accounts[1], 'offset_account' => $accounts[2],
        ])->assertSessionHasNoErrors()->assertRedirect($journal);
        $this->post("$journal/post", ['token' => session('loan_journal_preview.token')])->assertSessionHasNoErrors()->assertRedirect($journal);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $accounts[0], 'signed_minor' => 1000]);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $accounts[1], 'signed_minor' => -900]);
        $this->assertDatabaseHas('loan_journal_lines', ['chart_account_id' => $accounts[2], 'signed_minor' => -100]);

        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 12, 22)->setTime(14, 0));
        $this->post($list, [...$payload, 'period' => '2026-12'])->assertRedirect();
        $december = DB::table('loan_batches')->where('period', '2026-12-01')->sole()->id;
        $this->actingAs($chair)->post("$list/$december/review", ['decision' => 'approved', 'review_note' => 'December approved'])->assertRedirect();
        $this->actingAs($admin)->post("$list/$december/confirm", [
            'effective_date' => '2026-12-22', 'payroll_reference' => 'payroll-2',
        ])->assertRedirect();
        $this->assertDatabaseHas('loan_applications', ['id' => $loan, 'status' => 'repaid']);
        $this->assertDatabaseCount('loan_repayment_receipts', 3);
        $this->assertDatabaseCount('loan_repayment_allocations', 3);
        $this->get($path)->assertOk()->assertSee('PAYROLL-1')->assertSee('PAYROLL-2')->assertSee('Repaid');
        $this->get('/staff/loan-register?status=repaid')->assertOk()->assertSee('10415');
    }
}
