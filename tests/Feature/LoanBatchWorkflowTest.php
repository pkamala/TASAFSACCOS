<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LoanBatchWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_board_officer_can_sign_in_but_cannot_manage_members_or_prepare_lists(): void
    {
        $officer = User::factory()->create(['board_role' => 'secretary']);
        $this->post('/staff/login', ['email' => $officer->email, 'password' => 'password'])->assertRedirect('/staff/loan-applications');
        $this->get('/staff/loan-batches')->assertOk()->assertDontSee('Prepare a list');
        $this->get('/staff/members')->assertForbidden();
        $this->get('/staff/loan-pricing')->assertForbidden();
        $this->post('/staff/loan-batches', ['kind' => 'disbursement'])->assertForbidden();
    }
}
