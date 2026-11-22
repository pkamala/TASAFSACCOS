<?php

namespace App\Http\Controllers;

use App\LegacyLoanBalanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LegacyCutoverController extends Controller
{
    public function __invoke(Request $request, LegacyLoanBalanceReport $reports): View
    {
        $snapshots = $reports->snapshots();
        $lastEndedMonth = CarbonImmutable::now('Africa/Dar_es_Salaam')->startOfMonth()->subDay();
        $input = $request->validate([
            'snapshot' => ['nullable', 'string', Rule::in($snapshots)],
            'period' => ['nullable', 'regex:/\A[2-9]\d{3}-(?:0[1-9]|1[0-2])\z/', 'date_format:Y-m', 'before_or_equal:'.$lastEndedMonth->format('Y-m')],
        ]);
        $snapshot = $input['snapshot'] ?? $snapshots[0] ?? null;
        $period = $input['period'] ?? $lastEndedMonth->format('Y-m');
        $end = CarbonImmutable::createFromFormat('!Y-m', $period, 'Africa/Dar_es_Salaam')->endOfMonth();
        $asOf = $end->toDateString();
        $openingDate = $end->addDay()->toDateString();

        $sources = DB::table('legacy_onboarding_rows as source')
            ->leftJoin('legacy_loan_corrections as correction', function ($join): void {
                $join->on('correction.source_sha256', '=', 'source.source_sha256')
                    ->on('correction.source_table', '=', 'source.source_table')
                    ->on('correction.source_id', '=', 'source.source_id');
            })
            ->where('source.source_sha256', $snapshot)->whereIn('source.source_table', ['loans', 'loanschedules', 'repayments'])
            ->selectRaw("source.source_table, count(*) AS staged,
                count(*) FILTER (WHERE source.issues <> '[]'::jsonb AND correction.source_id IS NULL) AS pending")
            ->groupBy('source.source_table')->get()->keyBy('source_table');

        $loans = DB::table('verified_legacy_loan_balances')->where('source_sha256', $snapshot)->where('as_of', $asOf)
            ->selectRaw('count(*) AS verified, coalesce(sum(principal_minor), 0) AS principal_minor')->first();

        $latestBalances = DB::table('member_verified_balances')->where('as_of', $asOf)
            ->selectRaw('DISTINCT ON (member_id) member_id, savings_minor, shares_minor')->orderBy('member_id')->orderByDesc('id');
        $members = DB::table('members')->where('members.status', 'active')
            ->leftJoinSub($latestBalances, 'balance', 'balance.member_id', '=', 'members.id')
            ->selectRaw('count(*) AS active, count(balance.member_id) AS verified,
                coalesce(sum(balance.savings_minor), 0) AS savings_minor, coalesce(sum(balance.shares_minor), 0) AS shares_minor')->first();

        $opening = DB::table('ledger_opening_journals')->first(['effective_on', 'review_reference']);

        return view('staff.legacy-cutover', compact('snapshots', 'snapshot', 'period', 'lastEndedMonth', 'asOf', 'openingDate', 'sources', 'loans', 'members', 'opening'));
    }
}
