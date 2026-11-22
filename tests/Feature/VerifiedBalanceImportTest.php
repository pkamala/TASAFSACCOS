<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerifiedBalanceImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_import_verified_balances_only_after_a_clean_unchanged_dry_run(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = DB::table('members')->insertGetId([
            'first_name' => 'Asha', 'last_name' => 'Member', 'registration_no' => '10415',
            'email' => 'member@example.com', 'status' => 'active', 'legacy_member_id' => 53,
        ]);
        $path = '/staff/verified-balance-import';
        $header = "registration_no,legacy_member_id,as_of,savings_tzs,shares_tzs,evidence_reference\n";
        $file = fn (string $rows): UploadedFile => UploadedFile::fake()->createWithContent('opening.csv', $header.$rows);
        $validRow = "10415,53,2026-09-30,100000.25,50000,Reviewed statement\n";

        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create())->post("$path/preview")->assertForbidden();
        $this->actingAs($admin)->get("$path/template")->assertDownload('verified_opening_balances.csv');

        $this->post("$path/preview", ['opening_balances' => $file("10415,301,2026-09-30,100000.25,50000,Reviewed statement\n")])
            ->assertRedirect("$path#preview");
        $this->get($path)->assertOk()->assertSee('Legacy member ID does not match')->assertDontSee('Apply verified opening balances');
        $this->from($path)->post("$path/apply", ['token' => session('verified_balances_preview.token')])
            ->assertSessionHasErrors('opening_balances');
        $this->assertDatabaseEmpty('member_verified_balances');

        $this->post("$path/preview", ['opening_balances' => $file($validRow)])->assertRedirect("$path#preview");
        $this->get($path)->assertOk()->assertSee('1 ready')->assertSee('Apply verified opening balances');
        $token = session('verified_balances_preview.token');
        $this->assertDatabaseEmpty('member_verified_balances');
        $this->post("$path/apply", ['token' => $token])->assertRedirect($path);
        $opening = DB::table('member_verified_balances')->sole();
        $this->assertDatabaseHas('member_verified_balances', [
            'id' => $opening->id, 'member_id' => $member, 'savings_minor' => 10000025, 'shares_minor' => 5000000,
            'recorded_by' => $admin->id, 'import_sha256' => hash('sha256', $header.$validRow),
        ]);
        $this->from($path)->post("$path/apply", ['token' => $token])->assertSessionHasErrors('opening_balances');
        $this->post("$path/preview", ['opening_balances' => $file($validRow)])->assertRedirect("$path#preview");
        $this->get($path)->assertOk()->assertSee('1 already recorded')->assertDontSee('Apply verified opening balances');

        $changed = str_replace('100000.25', '110000.25', $validRow);
        $this->post("$path/preview", ['opening_balances' => $file($changed)])->assertRedirect("$path#preview");
        DB::table('member_fund_transactions')->insert([
            'member_id' => $member, 'member_verified_balance_id' => $opening->id, 'kind' => 'savings_deposit',
            'signed_minor' => 10000, 'effective_on' => '2026-11-01', 'reference' => 'RECEIPT',
            'recorded_by' => $admin->id, 'recorded_at' => now(),
        ]);
        $this->from($path)->post("$path/apply", ['token' => session('verified_balances_preview.token')])
            ->assertSessionHasErrors('opening_balances');
        $this->assertDatabaseCount('member_verified_balances', 1);
        $this->post("$path/preview", ['opening_balances' => $file($changed)])->assertRedirect("$path#preview");
        $this->get($path)->assertOk()->assertSee('Opening balance is locked by member activity');
    }
}
