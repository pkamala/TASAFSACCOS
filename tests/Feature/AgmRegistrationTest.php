<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgmRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_signed_in_members_register_once_with_their_saved_details(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 25)->setTime(10, 0));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = $this->member('10415');
        $owner = User::factory()->create(['member_id' => $member]);
        $other = $this->member('10416');
        $otherLogin = User::factory()->create(['member_id' => $other]);
        $event = ['title' => 'Annual General Meeting', 'venue' => 'TASAF Hall', 'starts_at' => '2026-12-05T09:30'];
        $this->get('/member/agm')->assertRedirect('/staff/login');
        $this->actingAs($owner)->post('/staff/agm', $event)->assertForbidden();
        $this->actingAs($admin)->get('/member/agm')->assertForbidden();
        $this->post('/staff/agm', $event)->assertRedirect();
        $meeting = DB::table('agm_meetings')->sole()->id;
        $staffPath = "/staff/agm/$meeting";
        $register = "/member/agm/$meeting/register";
        $this->actingAs($owner)->get('/member/agm')->assertOk()
            ->assertSee('Annual General Meeting')->assertSee('05 Dec 2026 09:30')->assertSee('Register for Annual General Meeting');
        $this->get('/member/account')->assertOk()->assertSee('Register for AGM');

        $this->post($register, ['member_id' => $other, 'member_name' => 'Override'])->assertRedirect('/member/agm');
        $this->post($register)->assertRedirect('/member/agm');
        $this->assertDatabaseCount('agm_registrations', 1);
        $this->assertDatabaseHas('agm_registrations', [
            'agm_meeting_id' => $meeting, 'member_id' => $member, 'registration_no' => '10415', 'member_name' => 'Asha A. Mushi',
            'email' => 'member-10415@example.com', 'phone' => '+255712345678',
        ]);

        $this->get('/member/agm')->assertOk()->assertSee('Registered')->assertDontSee('Register for Annual General Meeting');
        $this->get('/member/account')->assertOk()->assertDontSee('Register for AGM');
        $this->get("$staffPath/register.csv")->assertForbidden();
        $this->actingAs($admin)->patch("$staffPath/registration", ['open' => '0'])->assertRedirect($staffPath);
        $this->actingAs($otherLogin)->from('/member/agm')->post($register)->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('agm_registrations', 1);
        $this->actingAs($admin)->patch("$staffPath/registration", ['open' => '1'])->assertRedirect($staffPath);
        $this->actingAs($otherLogin)->post($register)->assertRedirect('/member/agm');
        $this->assertDatabaseCount('agm_registrations', 2);
    }

    public function test_admins_can_search_check_in_and_export_the_registration_snapshot(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $member = $this->member('10415');
        $owner = User::factory()->create(['member_id' => $member]);
        $this->actingAs($admin)->post('/staff/agm', ['title' => 'AGM', 'venue' => 'TASAF Hall', 'starts_at' => '2026-12-05T09:30'])->assertRedirect();
        $meeting = DB::table('agm_meetings')->sole()->id;
        $path = "/staff/agm/$meeting";
        $this->actingAs($owner)->post("/member/agm/$meeting/register")->assertRedirect();
        $registration = DB::table('agm_registrations')->sole()->id;
        DB::table('members')->where('id', $member)->update(['last_name' => 'Changed']);

        $this->patch("$path/registrations/$registration")->assertForbidden();
        $this->actingAs($admin)->get("$path?table_search=10415")->assertOk()->assertSee('Asha A. Mushi')
            ->assertSee('member-10415@example.com')->assertDontSee('Asha A. Changed');
        $this->get("$path?table_search=missing")->assertOk()->assertSee('0 records');
        $this->from($path)->patch("$path/registrations/$registration")->assertRedirect($path);
        $this->get($path)->assertOk()->assertSee('Checked in');
        $this->assertDatabaseHas('agm_registrations', ['id' => $registration, 'checked_in_by' => $admin->id]);
        $export = $this->get("$path/register.csv")->assertOk()->assertDownload("agm-$meeting-register.csv");
        $this->assertStringContainsString('Asha A. Mushi', $export->streamedContent());
    }

    private function member(string $number): int
    {
        return DB::table('members')->insertGetId([
            'registration_no' => $number, 'first_name' => 'Asha', 'middle_name' => 'A.', 'last_name' => 'Mushi',
            'email' => "member-$number@example.com", 'phone' => '+255712345678', 'status' => 'active',
        ]);
    }
}
