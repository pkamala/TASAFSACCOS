<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoanPricingController extends Controller
{
    public function edit(): View
    {
        $current = DB::table('loan_pricing_revisions')->orderByDesc('id')->first();
        $history = DB::table('loan_pricing_revisions as revision')
            ->join('users as admin', 'admin.id', '=', 'revision.changed_by')
            ->select('revision.*', 'admin.name as actor')->orderByDesc('revision.id')->simplePaginate(25);

        return view('staff.loan-applications.pricing', compact('current', 'history'));
    }

    public function update(Request $request): RedirectResponse
    {
        $percentage = ['required', 'numeric', 'decimal:0,2', 'between:0,100'];
        $charge = ['required', 'numeric', 'decimal:0,2', 'between:0,100000000000'];
        $data = $request->validate([
            'current_revision' => ['required', 'integer', 'min:0'],
            'annual_rate' => $percentage,
            'interest_method' => ['required', 'in:reducing,flat'],
            'insurance_rate' => $percentage,
            'processing_fee' => $charge,
            'other_charges' => $charge,
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $minorUnits = static fn ($value): int => (int) round((float) $value * 100);
        $values = [
            'annual_basis_points' => $minorUnits($data['annual_rate']),
            'interest_method' => $data['interest_method'],
            'insurance_basis_points' => $minorUnits($data['insurance_rate']),
            'processing_fee_minor' => $minorUnits($data['processing_fee']),
            'other_charges_minor' => $minorUnits($data['other_charges']),
        ];

        $changed = DB::transaction(function () use ($request, $data, $values): bool {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['loan-pricing']);
            $current = DB::table('loan_pricing_revisions')->orderByDesc('id')->first();
            if ((int) $data['current_revision'] !== ($current->id ?? 0)) {
                throw ValidationException::withMessages(['current_revision' => 'Pricing has changed. Review the current settings and try again.']);
            }

            if ($current && array_intersect_key((array) $current, $values) === $values) {
                return false;
            }

            DB::table('loan_pricing_revisions')->insert([
                ...$values,
                'changed_by' => $request->user()->id,
                'reason' => $data['reason'],
                'changed_at' => now(),
            ]);

            return true;
        }, 3);

        return redirect()->route('staff.loan-pricing.edit')
            ->with('status', $changed ? 'Loan pricing saved.' : 'Pricing is already set to those values.');
    }
}
