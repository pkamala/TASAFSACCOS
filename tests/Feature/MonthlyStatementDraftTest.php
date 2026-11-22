<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonthlyStatementDraftTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_drafts_use_month_end_figures_and_hold_members_with_unreconciled_legacy_loans(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = $this->member('10415', 'member@example.com');
        $snapshot = str_repeat('a', 64);
        $legacy = $this->member('10339', 'legacy@example.com', ['legacy_member_id' => 53, 'legacy_source_sha256' => $snapshot]);
        $this->member('10500', 'no-opening@example.com');
        DB::table('legacy_onboarding_rows')->insert([
            'source_sha256' => $snapshot, 'source_table' => 'loans', 'source_id' => 45,
            'data' => '{"member_id":"53"}', 'issues' => '[]',
        ]);

        $base = [
            'as_of' => '2026-09-30', 'savings_minor' => 100000, 'shares_minor' => 0,
            'evidence_reference' => 'Opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ];
        $opening = DB::table('member_verified_balances')->insertGetId([...$base, 'member_id' => $member]);
        DB::table('member_verified_balances')->insert([...$base, 'member_id' => $legacy]);
        $withdrawal = DB::table('member_savings_withdrawals')->insertGetId([
            'member_id' => $member, 'amount_minor' => 5000, 'status' => 'paid',
            'requested_by' => $admin->id, 'reason' => 'Withdrawal', 'requested_at' => now(),
        ]);
        foreach ([
            ['savings_deposit', 10000, '2026-09-30', null],
            ['savings_deposit', 25000, '2026-10-31', null],
            ['share_purchase', 2500, '2026-10-15', null],
            ['savings_withdrawal', -5000, '2026-10-20', $withdrawal],
            ['savings_deposit', 80000, '2026-11-01', null],
        ] as $index => [$kind, $amount, $date, $withdrawalId]) {
            DB::table('member_fund_transactions')->insert([
                'member_id' => $member, 'member_verified_balance_id' => $opening, 'kind' => $kind,
                'signed_minor' => $amount, 'effective_on' => $date, 'reference' => "REF-$index",
                'member_savings_withdrawal_id' => $withdrawalId,
                'recorded_by' => $admin->id, 'recorded_at' => now(),
            ]);
        }

        $loan = DB::table('loan_applications')->insertGetId([
            'submission_id' => (string) Str::uuid(), 'member_id' => $member, 'created_by' => $admin->id,
            'principal_minor' => 100000, 'insurance_minor' => 5000, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 0, 'term_months' => 1, 'interest_method' => 'flat',
            'first_due_date' => '2026-10-31', 'status' => 'disbursed', 'created_at' => now(),
        ]);
        DB::table('loan_disbursements')->insert([
            'loan_application_id' => $loan, 'transfer_reference' => 'TRANSFER', 'cash_amount_minor' => 100000,
            'transferred_on' => '2026-10-10', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('loan_application_installments')->insert([
            'loan_application_id' => $loan, 'number' => 1, 'due_date' => '2026-10-31', 'opening_minor' => 105000,
            'principal_minor' => 105000, 'interest_minor' => 0, 'payment_minor' => 105000, 'closing_minor' => 0,
        ]);
        foreach (['2026-10-31' => 30000, '2026-11-01' => 20000] as $date => $amount) {
            $receipt = DB::table('loan_repayment_receipts')->insertGetId([
                'loan_application_id' => $loan, 'receipt_reference' => "PAY-$date", 'received_on' => $date,
                'amount_minor' => $amount, 'recorded_by' => $admin->id, 'recorded_at' => now(),
            ]);
            DB::table('loan_repayment_allocations')->insert([
                'loan_application_id' => $loan, 'loan_repayment_receipt_id' => $receipt, 'installment_number' => 1,
                'principal_minor' => $amount, 'interest_minor' => 0,
            ]);
        }

        $path = '/staff/statement-drafts';
        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get("$path/download?period=2026-10")->assertForbidden();
        $this->actingAs($admin)->get($path)->assertOk()
            ->assertSee('1 ready for finance review')->assertSee('2 on hold')
            ->assertSee('750.00')->assertSee('275.00')->assertSee('1,300.00')
            ->assertSee('Legacy loans need reconciliation')->assertSee('Verified opening balances must predate the month');
        $download = $this->get("$path/download?period=2026-10")->assertDownload('monthly-statement-draft-2026-10.csv');
        $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), explode("\n", trim($download->streamedContent())));
        $this->assertSame([
            ['registration_no', 'email', 'loan_balance', 'monthly_contribution', 'savings_balance'],
            ['10415', 'member@example.com', '750.00', '275.00', '1300.00'],
        ], $rows);
        $this->from($path)->get("$path?period=2026-11")->assertSessionHasErrors('period');
        $this->assertDatabaseEmpty('monthly_member_statements');
    }

    private function member(string $registrationNo, string $email, array $extra = []): int
    {
        return DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => $registrationNo,
            'email' => $email, 'status' => 'active', ...$extra,
        ]);
    }
}
