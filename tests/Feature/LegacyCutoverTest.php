<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyCutoverTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cutover_shows_only_selected_snapshot_and_month_end_for_admins(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(12, 0));
        $path = '/staff/legacy-cutover';
        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $this->actingAs($admin)->get($path)->assertOk()->assertSee('No legacy loan snapshot has been staged.');

        $snapshot = str_repeat('a', 64);
        $other = str_repeat('b', 64);
        $source = ['source_sha256' => $snapshot, 'data' => '{}'];
        DB::table('legacy_onboarding_rows')->insert([
            [...$source, 'source_table' => 'loans', 'source_id' => 1, 'issues' => '[]'],
            [...$source, 'source_table' => 'repayments', 'source_id' => 2, 'issues' => '["conflict_loan_link","unresolved_loanschedule_id"]'],
            [...$source, 'source_table' => 'repayments', 'source_id' => 3, 'issues' => '["unresolved_loan_link"]'],
            [...$source, 'source_sha256' => $other, 'source_table' => 'loans', 'source_id' => 1, 'issues' => '["unresolved_member_id"]'],
        ]);
        DB::table('legacy_loan_corrections')->insert([
            'source_sha256' => $snapshot, 'source_table' => 'repayments', 'source_id' => 3, 'loan_id' => 1,
            'evidence_reference' => 'Receipt', 'reviewed_by' => 'Finance', 'reviewed_on' => '2026-11-22',
            'csv_sha256' => $other, 'applied_by' => $admin->id, 'applied_at' => now(),
        ]);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'member@example.com', 'status' => 'active',
        ]);
        $balance = [
            'member_id' => $member, 'source_id' => 1, 'as_of' => '2026-09-30',
            'principal_minor' => 45000, 'evidence_reference' => 'Finance report',
            'report_sha256' => $other, 'verified_by' => $admin->id, 'verified_at' => now(),
        ];
        DB::table('verified_legacy_loan_balances')->insert([
            [...$balance, 'source_sha256' => $snapshot],
            [...$balance, 'source_sha256' => $other, 'principal_minor' => 20000],
            [...$balance, 'source_sha256' => $snapshot, 'as_of' => '2026-10-31', 'principal_minor' => 80000],
        ]);
        $memberBalance = [
            'member_id' => $member, 'as_of' => '2026-09-30', 'evidence_reference' => 'Finance report',
            'recorded_by' => $admin->id, 'recorded_at' => now(),
        ];
        foreach ([[10000, 15000], [12000, 18000]] as [$savings, $shares]) {
            DB::table('member_verified_balances')->insert([...$memberBalance, 'savings_minor' => $savings, 'shares_minor' => $shares]);
        }
        DB::table('ledger_opening_journals')->insert([
            'effective_on' => '2026-11-01', 'review_reference' => 'Opening', 'source_sha256' => $other,
            'posted_by' => $admin->id, 'posted_at' => now(),
        ]);

        $this->get("$path?snapshot=$snapshot&period=2026-09")->assertOk()
            ->assertViewHas('sources', fn ($rows): bool => $rows->get('repayments')->staged === 2 && $rows->get('repayments')->pending === 1)
            ->assertSee('2026-09-30')->assertSee('450.00')->assertSee('120.00')->assertSee('180.00')
            ->assertSee('That journal belongs to a different cutover date.');
        $this->get('/staff/loan-reconciliation?snapshot='.$snapshot.'&as_of=2026-09-30')->assertOk()
            ->assertViewHas('snapshot', $snapshot)->assertViewHas('asOf', '2026-09-30');
        $this->from($path)->get("$path?period=2026-11")->assertRedirect($path)->assertSessionHasErrors('period');
    }
}
