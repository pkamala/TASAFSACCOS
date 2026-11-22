<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_an_active_member_and_rejects_a_duplicate_registration_number(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 11, 21)->setTime(22, 30));

        $member = [
            'registration_no' => '001a',
            'first_name' => 'Asha',
            'last_name' => 'Mushi',
            'email' => 'Asha@example.com',
            'phone' => '+255712345678',
        ];

        $this->artisan('members:register', $member)->assertSuccessful();

        $this->assertDatabaseHas('members', [
            ...$member,
            'registration_no' => '001A',
            'email' => 'asha@example.com',
            'joining_date' => '2026-11-22',
            'status' => 'active',
        ]);

        $this->artisan('members:register', [
            ...$member,
            'registration_no' => '001A',
            'email' => 'another@example.com',
        ])->expectsOutput('The registration no has already been taken.')->assertFailed();

        $this->assertDatabaseCount('members', 1);
    }
}
