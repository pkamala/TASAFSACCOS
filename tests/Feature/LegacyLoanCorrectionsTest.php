<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyLoanCorrectionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_review_snapshot_exceptions_and_download_the_four_templates(): void
    {
        $snapshot = str_repeat('a', 64);
        $other = str_repeat('b', 64);
        $rows = [
            [$snapshot, 'loans', 13, [], []],
            [$snapshot, 'loanschedules', 24, ['loan_id' => '13'], []],
            [$snapshot, 'repayments', 35, ['payment_id' => '611', 'rploan_id' => '14', 'loanschedule_id' => '24'], ['conflict_loan_link']],
            [$other, 'loans', 66, [], []],
            [$other, 'repayments', 9901, [], ['conflict_loan_link']],
        ];

        foreach ($rows as [$hash, $table, $id, $data, $issues]) {
            DB::table('legacy_onboarding_rows')->insert([
                'source_sha256' => $hash,
                'source_table' => $table,
                'source_id' => $id,
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                'issues' => json_encode($issues, JSON_THROW_ON_ERROR),
            ]);
        }

        $path = '/staff/loan-corrections';
        $download = "$path/templates";
        $this->get($path)->assertRedirect('/staff/login');
        $this->get("$download/loans")->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
        $this->get("$download/loans")->assertForbidden();

        $this->actingAs(User::factory()->create(['is_staff_admin' => true]));
        $this->get("$path?snapshot=$snapshot&issue=conflict_loan_link")->assertOk()
            ->assertSee('How to verify')
            ->assertSee('Compare the receipt, member account, and installment schedule.')
            ->assertViewHas('counts', fn (array $counts): bool => (int) $counts['conflict_loan_link'] === 1)
            ->assertSee('>611<', false)
            ->assertSee('>13<', false)
            ->assertDontSee('>9901<', false)
            ->assertSee('Approve the reconciled totals');

        foreach ([
            'loans' => 'loans.csv',
            'installments' => 'installments.csv',
            'repayment-allocations' => 'repayment_allocations.csv',
            'legacy-link-corrections' => 'legacy_link_corrections.csv',
        ] as $key => $filename) {
            $this->get("$download/$key")->assertOk()->assertDownload($filename);
        }

        $this->get("$download/unknown")->assertNotFound();
    }

    public function test_worklist_exports_each_unresolved_row_once_for_the_selected_snapshot(): void
    {
        $snapshot = str_repeat('a', 64);
        $other = str_repeat('b', 64);
        DB::table('legacy_onboarding_rows')->insert([
            ['source_sha256' => $snapshot, 'source_table' => 'loans', 'source_id' => 1, 'data' => '{}', 'issues' => '[]'],
            ['source_sha256' => $snapshot, 'source_table' => 'loanschedules', 'source_id' => 2, 'data' => '{"loan_id":"1"}', 'issues' => '[]'],
            ['source_sha256' => $snapshot, 'source_table' => 'repayments', 'source_id' => 3,
                'data' => '{"payment_id":"=1+1","monthly_id":"7","rploan_id":"9","loanschedule_id":"2"}',
                'issues' => '["conflict_loan_link","unresolved_loanschedule_id"]'],
            ['source_sha256' => $snapshot, 'source_table' => 'repayments', 'source_id' => 4, 'data' => '{}', 'issues' => '["unresolved_loan_link"]'],
            ['source_sha256' => $other, 'source_table' => 'loans', 'source_id' => 5, 'data' => '{}', 'issues' => '["unresolved_member_id"]'],
        ]);
        $admin = User::factory()->create(['is_staff_admin' => true]);
        DB::table('legacy_loan_corrections')->insert([
            'source_sha256' => $snapshot, 'source_table' => 'repayments', 'source_id' => 4,
            'loan_id' => 1, 'schedule_id' => 2, 'evidence_reference' => 'Receipt', 'reviewed_by' => 'Finance',
            'reviewed_on' => '2026-11-22', 'csv_sha256' => $other, 'applied_by' => $admin->id, 'applied_at' => now(),
        ]);

        $path = '/staff/loan-corrections/worklist';
        $url = "$path?snapshot=$snapshot";
        $this->get($url)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs($admin)->get($path.'?snapshot='.str_repeat('c', 64))->assertNotFound();
        $response = $this->get($url)->assertOk()->assertDownload('legacy-correction-worklist-aaaaaaaaaaaa.csv');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $csv = explode("\n", rtrim($response->streamedContent(), "\n"));
        $this->assertCount(2, $csv);
        $this->assertSame([
            $snapshot, 'repayments', '3', 'conflict_loan_link | unresolved_loanschedule_id', "'=1+1",
            '7', '9', '2', '1', '', '',
        ], str_getcsv($csv[1], escape: ''));
    }
}
