<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskInboxTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_board_task_inbox_excludes_own_requests_and_routes_other_officers_loans_correctly(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $chairMember = $this->member('10001');
        $secretaryMember = $this->member('10002');
        $chair = User::factory()->create(['member_id' => $chairMember, 'board_role' => 'chairperson']);
        $secretary = User::factory()->create(['member_id' => $secretaryMember, 'board_role' => 'secretary']);
        $chairLoan = $this->loan($admin, $chairMember);
        $secretaryLoan = $this->loan($admin, $secretaryMember);
        $ownSubmission = $this->loan($chair, $this->member('10003'));
        $batch = DB::table('loan_batches')->insertGetId([
            'kind' => 'repayment', 'period' => '2026-11-01', 'status' => 'prepared', 'items' => '[]',
            'prepared_by' => $chair->id, 'prepared_at' => now(),
        ]);

        $path = '/staff/tasks';
        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->getJson("$path/count")->assertForbidden();
        $this->get($path)->assertForbidden();

        $this->actingAs($chair)->get($path)->assertOk()
            ->assertSee(route('staff.loan-applications.show', [$secretaryMember, $secretaryLoan]))
            ->assertDontSee(route('staff.loan-applications.show', [$chairMember, $chairLoan]))
            ->assertDontSee(route('staff.loan-applications.show', [DB::table('loan_applications')->find($ownSubmission)->member_id, $ownSubmission]))
            ->assertDontSee(route('staff.loan-batches.show', $batch));
        $this->getJson("$path/count")->assertOk()->assertExactJson(['count' => 1]);

        $this->actingAs($secretary)->get($path)->assertOk()
            ->assertSee(route('staff.loan-applications.show', [$chairMember, $chairLoan]))
            ->assertSee(route('staff.loan-batches.show', $batch))
            ->assertDontSee(route('staff.loan-applications.show', [$secretaryMember, $secretaryLoan]));
        $this->getJson("$path/count")->assertExactJson(['count' => 3]);
        $this->post(route('staff.loan-batches.review', $batch), ['decision' => 'approved', 'review_note' => 'Checked'])
            ->assertRedirect(route('staff.loan-batches.show', $batch));
        $this->getJson("$path/count")->assertExactJson(['count' => 2]);

        $this->actingAs($admin)->get($path)->assertOk()
            ->assertSee('Review actual payroll deductions')
            ->assertSee(route('staff.payroll-deductions.show', $batch))
            ->assertDontSee('Review loan application');
        $this->getJson("$path/count")->assertExactJson(['count' => 1]);
    }

    public function test_administrator_tasks_disappear_when_journals_are_posted_and_months_are_closed(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 12, 5));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = $this->member('10001');
        DB::table('ledger_opening_journals')->insert([
            'effective_on' => '2026-11-01', 'review_reference' => 'Opening',
            'source_sha256' => str_repeat('a', 64), 'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        $balance = DB::table('member_verified_balances')->insertGetId([
            'member_id' => $member, 'as_of' => '2026-10-31', 'savings_minor' => 0, 'shares_minor' => 0,
            'evidence_reference' => 'Opening', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $fund = DB::table('member_fund_transactions')->insertGetId([
            'member_id' => $member, 'member_verified_balance_id' => $balance,
            'kind' => 'savings_deposit', 'signed_minor' => 10000, 'effective_on' => '2026-11-18',
            'reference' => 'NOV-DEPOSIT', 'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);

        $path = '/staff/tasks';
        $this->actingAs($admin)->get($path)->assertOk()->assertSee('Post member fund journal')
            ->assertSee(route('staff.member-fund-journals.show', $fund))->assertSee('Review month-end close');
        $this->getJson("$path/count")->assertExactJson(['count' => 2]);

        [$bank, $savings] = $this->accounts();
        DB::table('member_fund_journals')->insert([
            'member_fund_transaction_id' => $fund, 'debit_account_id' => $bank, 'credit_account_id' => $savings,
            'amount_minor' => 10000, 'transaction_date' => '2026-11-18', 'source_reference' => 'NOV-DEPOSIT',
            'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        $this->getJson("$path/count")->assertExactJson(['count' => 1]);
        $this->post(route('staff.ledger-periods.record'), [
            'month' => '2026-11', 'action' => 'close', 'reason' => 'Reviewed',
        ])->assertRedirect(route('staff.ledger-periods.index', ['month' => '2026-11']));
        $this->get($path)->assertOk()->assertSee('No tasks are waiting for your role.');
        $this->getJson("$path/count")->assertExactJson(['count' => 0]);
    }

    private function member(string $registration): int
    {
        return DB::table('members')->insertGetId([
            'first_name' => 'Member', 'last_name' => $registration, 'registration_no' => $registration,
            'email' => "member$registration@example.com", 'status' => 'active',
        ]);
    }

    private function loan(User $actor, int $member): int
    {
        return DB::table('loan_applications')->insertGetId([
            'submission_id' => Str::uuid()->toString(), 'member_id' => $member, 'created_by' => $actor->id,
            'principal_minor' => 10000, 'insurance_minor' => 0, 'processing_fee_minor' => 0, 'other_charges_minor' => 0,
            'annual_basis_points' => 0, 'term_months' => 1, 'interest_method' => 'flat',
            'first_due_date' => '2026-11-30', 'status' => 'submitted', 'created_at' => now(),
        ]);
    }

    private function accounts(): array
    {
        $source = ['legacy_source_sha256' => str_repeat('a', 64)];
        $category = DB::table('account_categories')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Category']);
        $type = DB::table('account_types')->insertGetId([...$source, 'legacy_id' => 1, 'name' => 'Type', 'account_category_id' => $category]);

        return array_map(fn (int $id, string $name): int => DB::table('chart_accounts')->insertGetId([
            ...$source, 'legacy_id' => $id, 'account_type_id' => $type, 'code' => (string) $id, 'name' => $name, 'is_ledger_account' => true,
        ]), [1, 2], ['Bank', 'Savings payable']);
    }
}
