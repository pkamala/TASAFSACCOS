<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoanDisbursementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_approved_loans_can_record_a_unique_confirmed_transfer(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(14, 0));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $reviewer = User::factory()->create(['board_role' => 'secretary']);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Mushi', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active',
        ]);
        $application = [
            'member_id' => $member, 'created_by' => $admin->id, 'principal_minor' => 100000000,
            'insurance_minor' => 2000000, 'processing_fee_minor' => 3000000, 'other_charges_minor' => 0,
            'annual_basis_points' => 1000, 'term_months' => 3, 'interest_method' => 'reducing',
            'first_due_date' => '2027-01-31', 'created_at' => now(),
        ];
        $ids = [];
        foreach (['submitted', 'approved', 'approved'] as $status) {
            $ids[] = DB::table('loan_applications')->insertGetId([
                ...$application, 'submission_id' => (string) Str::uuid(), 'status' => $status,
            ]);
        }
        [$pending, $approved, $other] = $ids;
        foreach ([$approved, $other] as $id) {
            DB::table('loan_application_decisions')->insert([
                'loan_application_id' => $id, 'reviewed_by' => $reviewer->id,
                'decision' => 'approved', 'reason' => 'Board review', 'reviewed_at' => now(),
            ]);
        }

        $prefix = "/staff/members/$member/loan-applications";
        $url = "$prefix/$approved";
        $list = '/staff/loan-batches';
        $this->post($list, ['kind' => 'disbursement', 'loan_ids' => [$approved]])->assertRedirect('/staff/login');
        $this->actingAs($admin)->post("$url/disburse", [])->assertNotFound();
        $this->from("$list/new")->post($list, ['kind' => 'disbursement', 'loan_ids' => [$pending]])->assertSessionHasErrors('batch');
        $this->post($list, ['kind' => 'disbursement', 'loan_ids' => [$approved]])->assertRedirect();
        $batch = DB::table('loan_batches')->sole()->id;
        $batchUrl = "$list/$batch";
        $payload = ['effective_date' => '2026-11-22', 'references' => [$approved => 'bank-transfer-42']];
        $pdf = static fn () => UploadedFile::fake()->createWithContent('checklist.pdf', "%PDF-1.4\n%%EOF");
        $this->from($batchUrl)->post("$batchUrl/confirm", $payload)->assertSessionHasErrors('batch');
        $this->post("$batchUrl/review", ['decision' => 'approved', 'review_note' => 'Approved list'])->assertForbidden();
        $this->actingAs($reviewer)->post("$batchUrl/review", ['decision' => 'approved', 'review_note' => 'Approved list'])->assertRedirect($batchUrl);
        $this->post("$batchUrl/confirm", [...$payload, 'bank_checklist' => $pdf()])->assertForbidden();
        $this->actingAs($admin)->from($batchUrl)->post("$batchUrl/confirm", $payload)->assertSessionHasErrors('batch');
        $this->post("$batchUrl/confirm", [...$payload, 'bank_checklist' => $pdf()])->assertRedirect($batchUrl);
        $this->from($batchUrl)->post("$batchUrl/confirm", [...$payload, 'bank_checklist' => $pdf()])->assertSessionHasErrors('batch');
        $this->get("$batchUrl/checklist")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertDatabaseHas('loan_batches', ['id' => $batch, 'status' => 'confirmed', 'reviewed_by' => $reviewer->id, 'confirmed_by' => $admin->id]);
        $this->assertDatabaseCount('loan_disbursements', 1);
        $this->assertDatabaseHas('loan_applications', ['id' => $approved, 'status' => 'disbursed']);
        $this->assertDatabaseHas('loan_applications', ['id' => $other, 'status' => 'approved']);
        $this->assertDatabaseHas('loan_disbursements', [
            'loan_application_id' => $approved,
            'transfer_reference' => 'BANK-TRANSFER-42',
            'cash_amount_minor' => 100000000,
            'loan_batch_id' => $batch,
            'recorded_by' => $admin->id,
        ]);
        $this->get($url)->assertOk()->assertSee('BANK-TRANSFER-42')->assertSee('Bank checklist');
        $this->get('/staff/loan-register?status=disbursed')->assertOk()->assertSee(">$approved<", false);
    }
}
