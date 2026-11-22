<?php

namespace App\Http\Controllers;

use App\LegacyLoanBalanceReport;
use App\VerifiedLegacyLoanBalances;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class VerifiedLegacyLoanBalanceController extends Controller
{
    public function index(Request $request, LegacyLoanBalanceReport $reports): View
    {
        $preview = $this->previewFor($request);
        $snapshots = $reports->snapshots();
        $counts = collect($preview['plan'] ?? [])->countBy('status');
        $ready = $counts->get('Ready', 0);
        $recorded = $counts->get('Already recorded', 0);
        $exceptions = $counts->sum() - $ready - $recorded;
        $history = DB::table('verified_legacy_loan_balances as balance')
            ->join('users as actor', 'actor.id', '=', 'balance.verified_by')
            ->selectRaw('balance.source_sha256, balance.as_of, balance.report_sha256, balance.evidence_reference, actor.name AS actor, max(balance.verified_at) AS verified_at, count(*) AS loan_count')
            ->groupBy('balance.source_sha256', 'balance.as_of', 'balance.report_sha256', 'balance.evidence_reference', 'actor.id')
            ->orderByDesc('verified_at')->orderBy('balance.report_sha256')->simplePaginate(25);

        return view('staff.verified-legacy-loan-balances', compact('snapshots', 'preview', 'ready', 'recorded', 'exceptions', 'history'));
    }

    public function preview(Request $request, LegacyLoanBalanceReport $reports, VerifiedLegacyLoanBalances $verifier): RedirectResponse
    {
        $request->session()->forget('verified_legacy_loans_preview');
        $data = $request->validate([
            'snapshot' => ['required', 'string', Rule::in($reports->snapshots())],
            'period' => ['required', 'regex:/\A[2-9]\d{3}-(?:0[1-9]|1[0-2])\z/'],
            'report' => ['required', 'file', 'max:1024'],
            'evidence' => ['required', 'string', 'max:500'],
        ]);
        if ($data['period'] >= now('Africa/Dar_es_Salaam')->format('Y-m')) {
            throw ValidationException::withMessages(['period' => 'Choose a closed month.']);
        }
        $asOf = CarbonImmutable::parse($data['period'].'-01', 'Africa/Dar_es_Salaam')->endOfMonth()->toDateString();
        $balances = $reports->read($data['report']);
        $snapshot = $data['snapshot'];
        $request->session()->put('verified_legacy_loans_preview', [
            'snapshot' => $snapshot, 'asOf' => $asOf, 'balances' => $balances,
            'plan' => $verifier->plan($snapshot, $asOf, $balances), 'evidence' => $data['evidence'],
            'actor' => $request->user()->id, 'token' => Str::random(40),
            'sha256' => hash_file('sha256', $data['report']->getPathname()),
        ]);

        return redirect()->route('staff.verified-legacy-loans.index')->withFragment('preview');
    }

    public function apply(Request $request, VerifiedLegacyLoanBalances $verifier): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:40']]);
        $preview = $this->previewFor($request);
        if (! $preview || ! hash_equals($preview['token'], $data['token'])) {
            throw ValidationException::withMessages(['report' => 'Run a fresh dry run first.']);
        }
        $count = $verifier->apply($preview, $request->user()->id);
        $request->session()->forget('verified_legacy_loans_preview');

        return redirect()->route('staff.verified-legacy-loans.index')->with('status', "$count legacy loan balances verified.");
    }

    private function previewFor(Request $request): ?array
    {
        $preview = $request->session()->get('verified_legacy_loans_preview');

        return ($preview['actor'] ?? null) === $request->user()->id ? $preview : null;
    }
}
