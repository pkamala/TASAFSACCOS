<?php

namespace Tests\Feature;

use App\LoanEligibility;
use App\MemberFunds;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberFundsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_confirmed_funds_and_approved_withdrawals_feed_the_member_balance_and_loan_check(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(12, 0));
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Mushi', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active', 'joining_date' => '2026-08-22',
        ]);
        $owner = User::factory()->create(['email' => 'asha@example.com', 'member_id' => $member, 'board_role' => 'chairperson']);
        $reviewer = User::factory()->create(['board_role' => 'secretary']);
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $path = "/staff/members/$member/funds";
        $deposit = ['kind' => 'savings_deposit', 'amount' => '25000.25', 'reference' => 'receipt-s1', 'effective_on' => '2026-11-22'];

        $this->actingAs($owner)->post($path, $deposit)->assertForbidden();
        $this->actingAs($admin)->from($path)->post($path, $deposit)->assertSessionHasErrors('funds');
        DB::table('member_verified_balances')->insert([
            'member_id' => $member, 'savings_minor' => 10000000, 'shares_minor' => 49000000,
            'as_of' => '2026-11-21', 'evidence_reference' => 'Opening statement',
            'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        DB::table('loan_eligibility_revisions')->insert([
            'min_savings_minor' => 10000000, 'min_shares_minor' => 5000000, 'reason' => 'Policy',
            'changed_by' => $admin->id, 'changed_at' => now(),
        ]);

        $this->from($path)->post($path, $deposit)->assertRedirect($path);
        $this->post($path, $deposit)->assertRedirect($path);
        $this->post($path, [...$deposit, 'kind' => 'share_purchase', 'amount' => '10000'])->assertRedirect($path);
        $this->assertDatabaseCount('member_fund_transactions', 2);
        $this->get($path)->assertOk()->assertSee('125,000.25')->assertSee('500,000.00')->assertSee('RECEIPT-S1');
        $this->assertNull(app(LoanEligibility::class)->details($member)['reason']);
        $this->actingAs($owner)->from('/member/account')->post('/member/savings-withdrawals', ['amount' => '200000', 'reason' => 'Cash'])
            ->assertSessionHasErrors('funds');
        $this->post('/member/savings-withdrawals', ['amount' => '30000.25', 'reason' => 'Cash'])->assertRedirect('/member/account');
        $withdrawal = DB::table('member_savings_withdrawals')->sole()->id;
        $url = "/staff/savings-withdrawals/$withdrawal";
        $review = ['decision' => 'approved', 'review_note' => 'Reviewed'];
        $pay = ['reference' => 'payout-1', 'effective_on' => '2026-11-22'];

        $this->post("$url/review", $review)->assertForbidden();
        $this->actingAs($admin)->from($url)->post("$url/pay", $pay)->assertSessionHasErrors('funds');
        $this->actingAs($reviewer)->from($url)->post("$url/review", $review)->assertRedirect($url);
        $reserved = app(MemberFunds::class)->balances($member);
        $this->assertSame(12500025, $reserved['savings']);
        $this->assertSame(9500000, $reserved['available']);
        DB::table('members')->insert([
            'first_name' => 'Unverified', 'last_name' => 'Member', 'registration_no' => 'UNKNOWN-1',
            'email' => 'unverified@example.com', 'status' => 'active',
        ]);
        $this->get('/staff/member-savings')->assertForbidden();
        $this->actingAs($admin)->get('/staff/member-savings')->assertOk()
            ->assertSeeInOrder(['Asha Mushi', 'Unverified Member', 'Awaiting verified opening balances'])->assertSee('30,000.25')
            ->assertViewHas('rows', fn ($rows): bool => $rows->contains(
                fn ($row): bool => $row->id === $member && (int) $row->savings === $reserved['savings']
                    && (int) $row->available === $reserved['available'],
            ));
        $this->get('/staff/member-savings?q=Asha%20Mushi', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee('95,000.00')->assertSee($path)->assertDontSee('UNKNOWN-1')->assertDontSee('<!doctype html>', false);
        $this->actingAs($reviewer);

        $this->assertSame('Available savings are below the required minimum.', app(LoanEligibility::class)->details($member)['reason']);
        $this->post("$url/pay", $pay)->assertForbidden();

        $this->actingAs($admin)->from($url)->post("$url/pay", $pay)->assertRedirect($url);
        $this->post("$url/pay", $pay)->assertSessionHasErrors('funds');
        $this->assertDatabaseHas('member_fund_transactions', [
            'member_id' => $member, 'member_savings_withdrawal_id' => $withdrawal,
            'signed_minor' => -3000025, 'recorded_by' => $admin->id,
        ]);
        $this->get($url)->assertOk()->assertSee('PAYOUT-1');
        $this->actingAs($owner)->get('/member/account')->assertOk()->assertSee('95,000.00')->assertSee('Latest withdrawal');
        $balance = app(MemberFunds::class)->balances($member);
        $this->assertSame(9500000, $balance['savings']);
        $this->assertSame(0, $balance['reserved']);
    }
}
