<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MemberRegistrationFormsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_registers_an_active_member_and_the_app_records_who_created_it(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(12, 0));
        Mail::fake();
        $path = '/staff/members';
        $payload = [
            'registration_no' => ' tasaf-81 ', 'first_name' => 'Asha', 'middle_name' => 'Jane', 'last_name' => 'Example',
            'email' => 'ASHA@example.com', 'phone' => '+255712345678', 'joining_date' => '2026-11-22',
        ];
        $this->get("$path/new")->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create(['board_role' => 'secretary']))->post($path, $payload)->assertForbidden();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $this->actingAs($admin)->get($path)->assertOk()->assertSee('New member');
        $this->get("$path/new")->assertOk()->assertSee('Register member');
        $this->from("$path/new")->post($path, [...$payload, 'joining_date' => '2026-11-23'])->assertSessionHasErrors('joining_date');
        $this->post($path, $payload)->assertRedirect();
        $member = DB::table('members')->sole();
        $this->assertDatabaseHas('members', [
            'id' => $member->id, 'registration_no' => 'TASAF-81', 'middle_name' => 'Jane',
            'email' => 'asha@example.com', 'status' => 'active', 'joining_date' => '2026-11-22',
        ]);
        $this->assertDatabaseHas('member_audits', ['member_id' => $member->id, 'actor_id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['member_id' => $member->id]);
        $this->get("$path/$member->id")->assertOk()->assertSee('Next of kin')->assertSee('Member registered');
        $this->post($path, $payload)->assertSessionHasErrors(['registration_no', 'email']);
        $this->assertDatabaseCount('members', 1);
        Mail::assertNothingOutgoing();
    }

    public function test_next_of_kin_contacts_can_be_added_and_edited_only_on_their_member_record(): void
    {
        $ids = [];
        foreach (['Asha', 'Baraka'] as $index => $firstName) {
            $ids[] = DB::table('members')->insertGetId([
                'registration_no' => 'KIN-'.($index + 1), 'first_name' => $firstName, 'last_name' => 'Example',
                'email' => strtolower($firstName).'@example.com', 'status' => 'active',
            ]);
        }
        [$member, $other] = $ids;
        $path = "/staff/members/$member/next-of-kin";
        $payload = ['full_name' => 'Jane Example', 'relationship' => 'Sibling', 'phone' => '+255712345678'];
        $this->actingAs(User::factory()->create(['member_id' => $member]))->get($path)->assertForbidden();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $this->actingAs($admin)->get($path)->assertOk()->assertSee('Add next of kin');
        $this->from($path)->post($path, [...$payload, 'phone' => 'invalid'])->assertSessionHasErrors('phone');
        $this->post($path, $payload)->assertRedirect($path);
        $contact = DB::table('member_next_of_kin')->sole();
        $this->assertSame($member, $contact->member_id);
        $this->patch("/staff/members/$other/next-of-kin/$contact->id", $payload)->assertNotFound();
        $this->patch("$path/$contact->id", [...$payload, 'phone' => '+255798765432', 'email' => 'kin@example.com'])->assertRedirect($path);
        $this->get($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Jane Example')->assertSee('+255798765432')->assertSee('kin@example.com');
        $this->get("/staff/members/$other/next-of-kin")->assertOk()->assertDontSee('Jane Example');
        $this->assertDatabaseCount('member_audits', 2);
    }
}
