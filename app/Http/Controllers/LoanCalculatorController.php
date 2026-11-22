<?php

namespace App\Http\Controllers;

use App\LoanSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class LoanCalculatorController extends Controller
{
    public function __invoke(Request $request, LoanSchedule $calculator): View
    {
        $defaults = ['amount' => '1000000', 'rate' => '10', 'months' => '12', 'method' => 'reducing', 'first_due_date' => ''];
        $chargeLabels = [
            'insurance' => 'Insurance',
            'processing_fee' => 'Loan processing fee',
            'other_charges' => 'Other charges',
        ];
        $defaults += array_fill_keys(array_keys($chargeLabels), '');
        $chargeRules = array_fill_keys(array_keys($chargeLabels), ['nullable', 'numeric', 'decimal:0,2', 'between:0,100000000000']);
        $validator = Validator::make($request->query() + $defaults, [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'between:100,100000000000'],
            'rate' => ['required', 'numeric', 'decimal:0,2', 'between:0,100'],
            'months' => ['required', 'integer', 'between:1,120'],
            'method' => ['required', 'in:reducing,flat'],
            'first_due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2089-12-31'],
        ] + $chargeRules);
        $inputs = array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '',
            array_intersect_key($request->query() + $defaults, $defaults),
        );
        $schedule = $financing = null;

        if (! $validator->fails()) {
            $input = $validator->validated();
            $amount = (int) round((float) $input['amount'] * 100);
            $charges = array_map(
                static fn ($value): int => (int) round((float) $value * 100),
                array_intersect_key($input, $chargeLabels),
            );
            $totalCharges = array_sum($charges);
            $financing = ['amount' => $amount, 'charges' => $charges, 'total_charges' => $totalCharges];

            $schedule = $calculator->calculate(
                $amount + $totalCharges,
                (int) round((float) $input['rate'] * 100),
                (int) $input['months'],
                $input['method'],
                $input['first_due_date'],
            );
        }

        return view('loan-calculator', [
            'inputs' => $inputs,
            'chargeLabels' => $chargeLabels,
            'financing' => $financing,
            'schedule' => $schedule,
            'errors' => $validator->errors(),
        ]);
    }
}
