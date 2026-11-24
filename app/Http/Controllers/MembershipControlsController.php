<?php

namespace App\Http\Controllers;

use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MembershipControlsController extends Controller
{
    public function minimums(): View
    {
        $history = DB::table('loan_eligibility_revisions as revision')
            ->join('users as actor', 'actor.id', '=', 'revision.changed_by')
            ->orderByDesc('revision.id')->simplePaginate(TablePage::size(), ['revision.*', 'actor.name as actor']);
        $current = DB::table('loan_eligibility_revisions')->orderByDesc('id')->first();

        return view('staff.membership-minimums', compact('history', 'current'));
    }

    public function saveMinimums(Request $request): RedirectResponse
    {
        $money = ['required', 'numeric', 'decimal:0,2', 'between:0,999999999999.99'];
        $data = $request->validate([
            'savings' => $money, 'shares' => $money,
            'current_revision' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $changed = DB::transaction(function () use ($request, $data): bool {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['loan-eligibility']);
            $current = DB::table('loan_eligibility_revisions')->orderByDesc('id')->first();
            if (($current->id ?? 0) !== (int) $data['current_revision']) {
                throw ValidationException::withMessages(['current_revision' => 'The minimums changed. Review the current values and try again.']);
            }
            $savings = $this->minor($data['savings']);
            $shares = $this->minor($data['shares']);
            if ($current && $current->min_savings_minor === $savings && $current->min_shares_minor === $shares) {
                return false;
            }

            DB::table('loan_eligibility_revisions')->insert([
                'min_savings_minor' => $savings, 'min_shares_minor' => $shares, 'changed_by' => $request->user()->id,
                'reason' => $data['reason'], 'changed_at' => now(),
            ]);

            return true;
        }, 3);

        return back()->with('status', $changed ? 'Loan minimums saved.' : 'The minimums are already set to those values.');
    }

    private function minor(string|int|float $value): int
    {
        return (int) round((float) $value * 100);
    }
}
