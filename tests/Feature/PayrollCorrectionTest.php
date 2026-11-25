<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollCorrectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_table_search_finds_matches_across_pages_and_preserves_the_status_filter(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $base = ['payroll_month' => '2026-02-01', 'issue' => 'Verify the payroll entry.'];
        DB::table('payroll_corrections')->insert(array_map(fn (int $row): array => [
            ...$base, 'reference' => "row-$row", 'entry' => "Ordinary entry $row",
        ], range(1, 12)));
        DB::table('payroll_corrections')->insert([
            [...$base, 'reference' => 'special-open', 'entry' => 'Savings adjustment 10%', 'resolved_at' => null],
            [...$base, 'reference' => 'similar-open', 'entry' => 'Savings adjustment 100', 'resolved_at' => null],
            [...$base, 'reference' => 'special-resolved', 'entry' => 'Previous savings adjustment 10%', 'resolved_at' => now()],
        ]);
        $path = '/staff/payroll-corrections';
        $this->actingAs($admin)->get($path)->assertOk()->assertDontSee('Savings adjustment 10%');

        $this->get($path.'?table_search=savings+10%25')->assertOk()
            ->assertSee('Savings adjustment 10%')->assertDontSee('Previous savings adjustment')
            ->assertSee('Showing 1–1 of 1 record');
        $this->get($path.'?status=resolved&table_search=savings+10%25')->assertOk()
            ->assertSee('Previous savings adjustment 10%')->assertSee('Showing 1–1 of 1 record');
    }

    public function test_admins_can_review_and_resolve_payroll_exceptions(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $id = DB::table('payroll_corrections')->insertGetId([
            'reference' => '2026-02-member-number',
            'payroll_month' => '2026-02-01',
            'entry' => 'Member number mismatch',
            'source_file' => 'February payroll.csv',
            'source_row' => 2,
            'issue' => 'Verify the membership number against the active register.',
        ]);
        $path = '/staff/payroll-corrections';
        $action = "$path/$id/resolve";

        $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
        $this->patch($action, ['resolution' => 'Confirmed'])->assertForbidden();
        $this->actingAs($admin)->get('/staff/data-imports')->assertOk()->assertSee($path)
            ->assertSeeInOrder(['Operations', 'Payment lists', 'Payroll corrections', 'Administration']);
        $this->get($path)->assertOk()->assertSee('February payroll.csv')->assertSee('Row 2');
        $this->from($path)->patch($action)->assertSessionHasErrors('resolution');
        $this->patch($action, ['resolution' => 'Confirmed the registration number against the signed register.'])->assertRedirect($path);

        $this->get($path)->assertOk()->assertSee('No open payroll corrections.');
        $this->get("$path?status=resolved")->assertOk()->assertSee('Confirmed the registration number against the signed register.');
        $this->patch($action, ['resolution' => 'Duplicate submission'])->assertRedirect();

        $this->assertDatabaseHas('payroll_corrections', [
            'id' => $id, 'resolved_by' => $admin->id,
            'resolution' => 'Confirmed the registration number against the signed register.',
        ]);
        $this->assertDatabaseEmpty('member_fund_transactions');
    }
}
