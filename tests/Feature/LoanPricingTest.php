<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoanPricingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_administrators_can_change_pricing_and_each_change_adds_an_audit_revision(): void
    {
        $path = '/staff/loan-pricing';
        $staff = DB::table('loan_categories')->where('name', 'Staff loan')->value('id');
        $emergency = DB::table('loan_categories')->where('name', 'Emergency loan')->value('id');
        $staffUrl = "$path?category=$staff";
        $initial = [
            'loan_category_id' => $staff,
            'current_revision' => 0,
            'annual_rate' => '10', 'interest_method' => 'flat', 'insurance_rate' => '1.55',
            'processing_fee' => '30000', 'other_charges' => '5000', 'reason' => 'Board decision one',
        ];
        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->patch($path, $initial)->assertForbidden();
        $this->assertDatabaseEmpty('loan_pricing_revisions');

        $admin = User::factory()->create(['is_staff_admin' => true]);
        $this->actingAs($admin)->patch($path, $initial)->assertRedirect($staffUrl);
        $first = DB::table('loan_pricing_revisions')->sole();
        $change = [...$initial, 'current_revision' => $first->id, 'annual_rate' => '11', 'reason' => 'Board decision two'];
        $this->patch($path, $change)->assertRedirect($staffUrl);
        $this->from($path)->patch($path, $change)->assertSessionHasErrors('current_revision');
        $latest = DB::table('loan_pricing_revisions')->orderByDesc('id')->first();
        $this->patch($path, [...$change, 'current_revision' => $latest->id])->assertRedirect($staffUrl);
        $this->assertDatabaseCount('loan_pricing_revisions', 2);
        $this->assertDatabaseHas('loan_pricing_revisions', [
            'id' => $first->id, 'annual_basis_points' => 1000, 'insurance_basis_points' => 155,
            'changed_by' => $admin->id, 'reason' => 'Board decision one',
        ]);
        $this->assertDatabaseHas('loan_pricing_revisions', [
            'id' => $latest->id, 'annual_basis_points' => 1100, 'changed_by' => $admin->id, 'reason' => 'Board decision two',
        ]);
        $this->patch($path, [
            ...$initial, 'loan_category_id' => $emergency, 'annual_rate' => '18', 'reason' => 'Emergency loan terms',
        ])->assertRedirect("$path?category=$emergency");
        $this->get($staffUrl)->assertOk()->assertSee('Pricing history')->assertSee('Board decision one')->assertSee('Board decision two')
            ->assertDontSee('Emergency loan terms');
        $this->get("$path?category=$emergency")->assertOk()->assertSee('Emergency loan terms')->assertDontSee('Board decision one');
        $this->get("/loan-calculator?category=$staff&rate=99")->assertOk()->assertSee('id="rate" value="11" readonly', false);
        $this->get("/loan-calculator?category=$emergency&rate=99")->assertOk()->assertSee('id="rate" value="18" readonly', false);
    }

    public function test_the_print_register_also_filters_legacy_loans_by_their_exported_status(): void
    {
        $snapshot = str_repeat('a', 64);
        DB::table('members')->insert([
            'first_name' => 'Asha', 'last_name' => 'Mushi', 'registration_no' => '10415',
            'email' => 'asha@example.com', 'status' => 'active',
            'legacy_member_id' => 53, 'legacy_source_sha256' => $snapshot,
        ]);
        foreach ([7 => 'Pending Approval', 8 => 'paid'] as $id => $status) {
            DB::table('legacy_onboarding_rows')->insert([
                'source_sha256' => $snapshot, 'source_table' => 'loans', 'source_id' => $id,
                'data' => json_encode([
                    'member_id' => '53', 'loan_status' => $status, 'loan_code' => "LEGACY-$id",
                    'principle' => '500000', 'duration' => '12',
                ], JSON_THROW_ON_ERROR),
                'issues' => '[]',
            ]);
        }

        $this->actingAs(User::factory()->create(['is_staff_admin' => true]))
            ->get('/staff/loan-register?source=legacy&status=Pending%20Approval')
            ->assertOk()->assertSee('LEGACY-7')->assertDontSee('LEGACY-8')->assertSee('Print register');
    }
}
