<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplyLegacyLoanCorrectionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_corrections_require_preview_and_apply_with_audit_without_changing_the_snapshot(): void
    {
        $snapshot = str_repeat('b', 64);
        $rows = [
            ['loans', 7, ['loan_code' => 'LOAN-007', 'member_id' => '53'], []],
            ['loans', 8, ['loan_code' => 'LOAN-008', 'member_id' => '53'], []],
            ['loanschedules', 21, [
                'loan_id' => '99', 'month' => '2', 'duedate' => '2025-11-30',
                'monthprinciple' => '40000', 'monthinterest' => '2000', 'status' => 'open',
            ], ['unresolved_loan_id']],
            ['repayments', 31, [
                'resolved_loan_id' => null, 'rploan_id' => '99', 'payment_id' => '45671',
                'loanschedule_id' => '88', 'paymentdate' => '2025-11-30',
                'principlepayed' => '40000', 'interestpayed' => '2000', 'amountpayed' => '42000',
            ], ['unresolved_loan_link', 'unresolved_loanschedule_id']],
        ];
        foreach ($rows as [$table, $id, $data, $flags]) {
            DB::table('legacy_onboarding_rows')->insert([
                'source_sha256' => $snapshot,
                'source_table' => $table,
                'source_id' => $id,
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                'issues' => json_encode($flags, JSON_THROW_ON_ERROR),
            ]);
        }
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha',
            'last_name' => 'Mushi',
            'registration_no' => '10415',
            'email' => 'asha@example.com',
            'status' => 'active',
            'legacy_member_id' => 53,
            'legacy_source_sha256' => $snapshot,
        ]);

        $admin = User::factory()->create(['is_staff_admin' => true]);
        $url = '/staff/loan-corrections';
        $header = trim(file_get_contents(base_path('templates/loan-import/legacy_link_corrections.csv')))."\n";
        $asOf = '2025-12-01';
        $schedule = "$snapshot,loanschedules,21,7,,Signed schedule,Finance reviewer,$asOf\n";
        $repayment = "$snapshot,repayments,31,7,21,Receipt 45671,Finance reviewer,$asOf\n";
        $this->post("$url/apply", ['token' => str_repeat('x', 40)])->assertRedirect('/staff/login');

        $this->actingAs($admin)->from($url)->post("$url/apply", ['token' => str_repeat('x', 40)])
            ->assertSessionHasErrors('corrections');

        $wrong = str_replace(',31,7,21,', ',31,8,21,', $repayment);
        $this->from($url)->post("$url/preview", [
            'snapshot' => $snapshot,
            'corrections' => UploadedFile::fake()->createWithContent('corrections.csv', $header.$wrong.$schedule),
        ])->assertSessionHasErrors('corrections');
        $this->assertDatabaseEmpty('legacy_loan_corrections');

        $csv = $header.$repayment.$schedule;
        $preview = $this->post("$url/preview", [
            'snapshot' => $snapshot,
            'corrections' => UploadedFile::fake()->createWithContent('corrections.csv', $csv),
        ]);
        $preview->assertRedirect("$url?snapshot=$snapshot#correction-upload");
        $token = session('legacy_corrections_preview.token');
        $this->assertDatabaseEmpty('legacy_loan_corrections');
        $this->get("$url?snapshot=$snapshot")->assertOk()->assertSee('Dry run passed')->assertSee('Receipt 45671');

        $this->post("$url/apply", ['token' => $token])->assertRedirect("$url?snapshot=$snapshot");
        $this->assertDatabaseCount('legacy_loan_corrections', 2);
        $this->assertDatabaseHas('legacy_loan_corrections', [
            'source_sha256' => $snapshot,
            'source_table' => 'repayments',
            'source_id' => 31,
            'loan_id' => 7,
            'schedule_id' => 21,
            'applied_by' => $admin->id,
            'csv_sha256' => hash('sha256', $csv),
        ]);
        $this->assertSame('99', DB::table('legacy_onboarding_rows')->where('source_table', 'loanschedules')
            ->selectRaw("data->>'loan_id' AS loan_id")->sole()->loan_id);

        $this->get("$url?snapshot=$snapshot&issue=unresolved_loan_link")->assertOk()
            ->assertViewHas('counts', fn (array $counts): bool => (int) $counts['unresolved_loan_link'] === 0)
            ->assertSee('No rows flagged for this check.')
            ->assertSee($admin->name);
        $this->get("/staff/members/$member/legacy-loans/7")->assertOk()
            ->assertSee('>45671<', false)->assertSee('>21<', false)->assertSee('>2<', false);
        $this->from($url)->post("$url/apply", ['token' => $token])->assertSessionHasErrors('corrections');
        $this->assertDatabaseCount('legacy_loan_corrections', 2);
    }
}
