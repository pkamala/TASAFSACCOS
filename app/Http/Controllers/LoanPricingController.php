<?php

namespace App\Http\Controllers;

use App\LoanPricing;
use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoanPricingController extends Controller
{
    public function edit(Request $request, LoanPricing $pricing): View
    {
        $data = $request->validate(['category' => ['nullable', 'integer', 'exists:loan_categories,id']]);
        $categories = $pricing->categories();
        $category = (int) ($data['category'] ?? $categories->first()->id);
        $current = $pricing->current($category);
        $history = DB::table('loan_pricing_revisions as revision')
            ->join('users as admin', 'admin.id', '=', 'revision.changed_by')
            ->where(fn ($query) => $query->where('revision.loan_category_id', $category)->orWhereNull('revision.loan_category_id'))
            ->select('revision.*', 'admin.name as actor')->orderByDesc('revision.id')
            ->tap(TablePage::search(['revision.reason', 'revision.interest_method', 'revision.changed_at', 'admin.name']))
            ->paginate(TablePage::size());

        return view('staff.loan-applications.pricing', compact('current', 'history', 'categories', 'category'));
    }

    public function update(Request $request, LoanPricing $pricing): RedirectResponse
    {
        $percentage = ['required', 'numeric', 'decimal:0,2', 'between:0,100'];
        $charge = ['required', 'numeric', 'decimal:0,2', 'between:0,100000000000'];
        $data = $request->validate([
            'loan_category_id' => ['required', 'integer', 'exists:loan_categories,id'],
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

        $category = (int) $data['loan_category_id'];
        $changed = DB::transaction(function () use ($request, $data, $values, $pricing, $category): bool {
            $pricing->lock($category, exclusive: true);
            $current = $pricing->current($category);
            if ((int) $data['current_revision'] !== ($current->id ?? 0)) {
                throw ValidationException::withMessages(['current_revision' => 'Pricing has changed. Review the current settings and try again.']);
            }

            if ($current?->loan_category_id === $category && array_intersect_key((array) $current, $values) === $values) {
                return false;
            }

            DB::table('loan_pricing_revisions')->insert([
                ...$values,
                'loan_category_id' => $category,
                'changed_by' => $request->user()->id,
                'reason' => $data['reason'],
                'changed_at' => now(),
            ]);

            return true;
        }, 3);

        return redirect()->route('staff.loan-pricing.edit', compact('category'))
            ->with('status', $changed ? 'Loan pricing saved.' : 'Pricing is already set to those values.');
    }
}
