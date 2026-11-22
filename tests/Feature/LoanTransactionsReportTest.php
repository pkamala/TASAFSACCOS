<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoanTransactionsReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_report_filters_by_actual_transaction_date_and_counts_a_split_receipt_once(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Mushi', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active',
        ]);
        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 50000000, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 1000, 'term_months' => 2, 'interest_method' => 'reducing',
            'first_due_date' => '2026-10-30', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        $batch = DB::table('loan_batches')->insertGetId([
            'kind' => 'repayment', 'period' => '2026-11-01', 'items' => '[]', 'status' => 'confirmed',
            'prepared_by' => $admin->id, 'prepared_at' => now(),
        ]);
        DB::table('loan_disbursements')->insert([
            'loan_application_id' => $loan, 'transfer_reference' => 'OCT-TRANSFER', 'transferred_on' => '2026-10-15',
            'cash_amount_minor' => 50000000, 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $receipt = DB::table('loan_repayment_receipts')->insertGetId([
            'loan_application_id' => $loan, 'receipt_reference' => 'NOV-PAYROLL', 'received_on' => '2026-11-15',
            'amount_minor' => 2200000, 'recorded_by' => $admin->id, 'recorded_at' => now(), 'loan_batch_id' => $batch,
        ]);
        foreach ([1, 2] as $number) {
            DB::table('loan_application_installments')->insert([
                'loan_application_id' => $loan, 'number' => $number, 'due_date' => '2026-11-20',
                'opening_minor' => 50000000, 'principal_minor' => 1000000, 'interest_minor' => 100000,
                'payment_minor' => 1100000, 'closing_minor' => 49000000,
            ]);
            DB::table('loan_repayment_allocations')->insert([
                'loan_application_id' => $loan, 'loan_repayment_receipt_id' => $receipt, 'installment_number' => $number,
                'principal_minor' => 1000000, 'interest_minor' => 100000,
            ]);
        }

        $path = '/staff/loan-transactions';
        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get($path)->assertForbidden();

        $this->actingAs($admin)->get($path)->assertOk()
            ->assertSee('NOV-PAYROLL')->assertDontSee('OCT-TRANSFER')
            ->assertSee("/staff/loan-batches/$batch")
            ->assertViewHas('totals', [
                'cash' => 0, 'receipts' => 2200000, 'principal' => 2000000, 'interest' => 200000,
            ]);
        $this->get("$path?from=2026-10-15&to=2026-11-15&kind=disbursements")->assertOk()
            ->assertSee('OCT-TRANSFER')->assertDontSee('NOV-PAYROLL')->assertSee('500,000.00');
        $this->from($path)->get("$path?from=2026-11-15&to=2026-11-14")->assertSessionHasErrors('to');
    }
}
