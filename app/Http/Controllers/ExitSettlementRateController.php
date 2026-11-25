<?php

namespace App\Http\Controllers;

use App\ExitSettlementRates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExitSettlementRateController extends Controller
{
    public function edit(ExitSettlementRates $rates): View
    {
        return view('staff.member-exits.rates', ['rates' => $rates->current()]);
    }

    public function update(Request $request, ExitSettlementRates $rates): RedirectResponse
    {
        $percentage = ['required', 'numeric', 'decimal:0,2', 'between:0,100'];
        $data = $request->validate([
            'current_revision' => ['required', 'integer', 'min:0'],
            'savings_interest_rate' => $percentage,
            'withholding_rate' => $percentage,
        ]);

        DB::transaction(function () use ($request, $rates, $data): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['exit-settlement-rates']);
            $current = $rates->current();
            if ($current->id !== (int) $data['current_revision']) {
                throw ValidationException::withMessages(['current_revision' => 'The rates changed. Review the current values and try again.']);
            }

            $interest = (int) round((float) $data['savings_interest_rate'] * 100);
            $withholding = (int) round((float) $data['withholding_rate'] * 100);
            if ($current->savings_interest_basis_points === $interest && $current->withholding_basis_points === $withholding) {
                return;
            }

            DB::table('exit_settlement_rate_revisions')->insert([
                'savings_interest_basis_points' => $interest,
                'withholding_basis_points' => $withholding,
                'changed_by' => $request->user()->id,
                'changed_at' => now(),
            ]);
        }, 3);

        return redirect()->route('staff.exit-settlement-rates.edit')->with('status', 'Exit settlement rates saved.');
    }
}
