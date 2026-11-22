<?php

namespace App\Http\Controllers;

use App\LoanPricing;
use App\LoanSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class LoanCalculatorController extends Controller
{
    public function __invoke(Request $request, LoanSchedule $calculator, LoanPricing $prices): View
    {
        $categories = $prices->categories();
        $selected = $request->query('category');
        $category = is_string($selected) || is_int($selected) ? $categories->firstWhere('id', $selected) : null;
        $pricing = $prices->current(($category ?? $categories->first())->id);
        $method = $pricing->interest_method ?? 'reducing';
        $insuranceBasisPoints = $pricing->insurance_basis_points ?? 0;
        $annualBasisPoints = $pricing->annual_basis_points ?? 1000;
        $defaults = ['amount' => '1000000', 'months' => '12', 'first_due_date' => ''];
        $chargeLabels = [
            'insurance' => 'Insurance',
            'processing_fee' => 'Loan processing fee',
            'other_charges' => 'Other charges',
        ];
        $validator = Validator::make($request->query() + $defaults, [
            'category' => ['nullable', 'integer', 'exists:loan_categories,id'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'between:100,100000000000'],
            'months' => ['required', 'integer', 'between:1,120'],
            'first_due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2089-12-31'],
        ]);
        $inputs = array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '',
            array_intersect_key($request->query() + $defaults, $defaults),
        );
        $schedule = $financing = null;
        $charges = [
            'insurance' => null,
            'processing_fee' => $pricing->processing_fee_minor ?? 0,
            'other_charges' => $pricing->other_charges_minor ?? 0,
        ];
        if (! $validator->errors()->has('amount')) {
            $amount = (int) round((float) $inputs['amount'] * 100);
            $charges['insurance'] = intdiv($amount * $insuranceBasisPoints + 5000, 10000);
        }

        if (! $validator->fails()) {
            $input = $validator->validated();
            $totalCharges = array_sum($charges);
            $financing = ['amount' => $amount, 'charges' => $charges, 'total_charges' => $totalCharges];

            $schedule = $calculator->calculate(
                $amount + $totalCharges,
                $annualBasisPoints,
                (int) $input['months'],
                $method,
                $input['first_due_date'],
            );
        }

        return view('loan-calculator', [
            'inputs' => $inputs,
            'categories' => $categories,
            'category' => ($category ?? $categories->first())->id,
            'examplePricing' => $pricing === null,
            'method' => $method,
            'annualRate' => $annualBasisPoints / 100,
            'insuranceRate' => $insuranceBasisPoints / 100,
            'charges' => $charges,
            'chargeLabels' => $chargeLabels,
            'financing' => $financing,
            'schedule' => $schedule,
            'errors' => $validator->errors(),
        ]);
    }
}
