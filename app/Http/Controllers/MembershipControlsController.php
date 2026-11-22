<?php

namespace App\Http\Controllers;

use App\LoanEligibility;
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

    public function balances(int $member, LoanEligibility $eligibility): View
    {
        $profile = DB::table('members')->find($member, ['id', 'first_name', 'last_name', 'registration_no']);
        abort_if($profile === null, 404);
        $history = DB::table('member_verified_balances as balance')
            ->join('users as actor', 'actor.id', '=', 'balance.recorded_by')
            ->where('balance.member_id', $member)->orderByDesc('balance.as_of')->orderByDesc('balance.id')
            ->simplePaginate(TablePage::size(), ['balance.*', 'actor.name as actor']);

        return view('staff.member-verified-balances', ['eligibility' => $eligibility->details($member), ...compact('profile', 'history')]);
    }

    public function saveBalances(Request $request, int $member): RedirectResponse
    {
        $money = ['required', 'numeric', 'decimal:0,2', 'between:0,999999999999.99'];
        $data = $request->validate([
            'savings' => $money, 'shares' => $money,
            'as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'evidence_reference' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $member, $data): void {
            abort_if(DB::table('members')->lockForUpdate()->find($member) === null, 404);
            if (DB::table('member_fund_transactions')->where('member_id', $member)->exists()
                || DB::table('member_savings_withdrawals')->where('member_id', $member)->where('status', 'approved')->exists()) {
                throw ValidationException::withMessages(['evidence_reference' => 'The opening balance is locked after a posted transaction or approved withdrawal.']);
            }
            $record = [
                'member_id' => $member, 'as_of' => $data['as_of'],
                'savings_minor' => $this->minor($data['savings']), 'shares_minor' => $this->minor($data['shares']),
                'evidence_reference' => $data['evidence_reference'],
            ];
            $latest = DB::table('member_verified_balances')->where('member_id', $member)
                ->where('as_of', $data['as_of'])->orderByDesc('id')->first();
            if (! $latest || array_intersect_key((array) $latest, $record) != $record) {
                DB::table('member_verified_balances')->insert([
                    ...$record, 'recorded_by' => $request->user()->id, 'recorded_at' => now(),
                ]);
            }
        }, 3);

        return back()->with('status', 'Verified balances saved.');
    }

    private function minor(string|int|float $value): int
    {
        return (int) round((float) $value * 100);
    }
}
