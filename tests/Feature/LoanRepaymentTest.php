<?php

namespace Tests\Feature;

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

        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 12, 22)->setTime(14, 0));
        $this->post($list, [...$payload, 'period' => '2026-12'])->assertRedirect();
        $december = DB::table('loan_batches')->where('period', '2026-12-01')->sole()->id;
        $this->actingAs($chair)->post("$list/$december/review", ['decision' => 'approved', 'review_note' => 'December approved'])->assertRedirect();
        $this->actingAs($admin)->post("$list/$december/confirm", [
            'effective_date' => '2026-12-22', 'payroll_reference' => 'payroll-2',
        ])->assertRedirect();
        $this->assertDatabaseHas('loan_applications', ['id' => $loan, 'status' => 'repaid']);
        $this->assertDatabaseCount('loan_repayment_receipts', 2);
        $this->assertDatabaseCount('loan_repayment_allocations', 2);
        $this->get($path)->assertOk()->assertSee('PAYROLL-1')->assertSee('PAYROLL-2')->assertSee('Repaid');
        $this->get('/staff/loan-register?status=repaid')->assertOk()->assertSee('10415');
    }
}
