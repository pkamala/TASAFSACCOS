<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LoanCalculatorTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reducing_balance_schedule_keeps_the_payment_consistent_and_closes_the_loan(): void
    {
        $this->get('/loan-calculator?amount=1200000&rate=12&months=3&method=reducing&first_due_date=2028-01-31')
            ->assertOk()
            ->assertSee('Repayment schedule')
            ->assertSee('2028-02-29')
            ->assertSee('2028-03-31')
            ->assertViewHas('schedule', function (array $schedule): bool {
                $rows = $schedule['rows'];

                return count($rows) === 3
                    && $rows[0]['interest'] === 1200000
                    && $rows[1]['interest'] < $rows[0]['interest']
                    && $rows[0]['payment'] === $rows[1]['payment']
                    && $rows[0]['closing'] === $rows[1]['opening']
                    && $rows[2]['closing'] === 0
                    && array_sum(array_column($rows, 'principal')) === 120000000
                    && array_sum(array_column($rows, 'payment')) === $schedule['total'];
            });
    }

    public function test_flat_and_zero_interest_schedules_display_the_expected_totals(): void
    {
        $this->get('/loan-calculator?amount=1200000&rate=12&months=6&method=flat')
            ->assertOk()
            ->assertViewHas('schedule', fn (array $schedule): bool => $schedule['interest'] === 7200000
                && $schedule['rows'][5]['interest'] === 1200000
                && $schedule['rows'][5]['closing'] === 0);

        $this->get('/loan-calculator?amount=100&rate=0&months=3&method=reducing')
            ->assertOk()
            ->assertViewHas('schedule', fn (array $schedule): bool => $schedule['total'] === 10000
                && $schedule['rows'][1]['payment'] === 3334
                && $schedule['rows'][2]['closing'] === 0);
    }

    public function test_charges_increase_the_opening_balance_and_accrue_interest_under_both_methods(): void
    {
        $query = 'amount=1000000&rate=12&months=12&insurance=20000.25&processing_fee=30000&other_charges=5000.75';

        foreach (['reducing', 'flat'] as $method) {
            $this->get("/loan-calculator?$query&method=$method")
                ->assertOk()
                ->assertSee('Total charges')
                ->assertSee('55,001.00')
                ->assertSee('Total financed')
                ->assertSee('1,055,001.00')
                ->assertViewHas('schedule', fn (array $schedule): bool => $schedule['rows'][0]['opening'] === 105500100
                    && $schedule['rows'][0]['interest'] === 1055001
                    && $schedule['rows'][11]['closing'] === 0
                    && array_sum(array_column($schedule['rows'], 'principal')) === 105500100);
        }
    }

    public function test_invalid_charges_prevent_schedule_calculation(): void
    {
        $this->get('/loan-calculator?insurance=-1&processing_fee=0.001&other_charges[]=2')
            ->assertOk()
            ->assertViewHas('errors', fn ($errors): bool => $errors->has(['insurance', 'processing_fee', 'other_charges']))
            ->assertViewHas('schedule', null);
    }

    public function test_invalid_inputs_show_errors_without_a_schedule(): void
    {
        $this->get('/loan-calculator?months=0&amount[]=1000&rate=-5')
            ->assertOk()
            ->assertSee('The months field must be between 1 and 120.')
            ->assertViewHas('schedule', null)
            ->assertDontSee('Repayment schedule');
    }
}
