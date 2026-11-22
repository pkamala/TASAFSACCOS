<?php

namespace App\Http\Controllers;

use App\LedgerReportData;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LoanLedgerReportController extends Controller
{
    public function index(Request $request, LedgerReportData $ledger): View
    {
        [$from, $to] = $ledger->period($request);
        $accounts = $ledger->lines()->where('line.transaction_date', '<=', $to)
            ->join('chart_accounts as account', 'account.id', '=', 'line.chart_account_id')
            ->groupBy('account.id')
            ->select(['account.id', 'account.code', 'account.name', 'account.legacy_id'])
            ->selectRaw("coalesce(sum(line.signed_minor) FILTER (WHERE line.transaction_date < ? OR (line.transaction_date = ? AND line.source_kind = 'opening')), 0) as opening_minor", [$from, $from])
            ->selectRaw("coalesce(sum(line.signed_minor) FILTER (WHERE (line.transaction_date > ? OR (line.transaction_date = ? AND line.source_kind <> 'opening')) AND line.signed_minor > 0), 0) as debit_minor", [$from, $from])
            ->selectRaw("coalesce(-sum(line.signed_minor) FILTER (WHERE (line.transaction_date > ? OR (line.transaction_date = ? AND line.source_kind <> 'opening')) AND line.signed_minor < 0), 0) as credit_minor", [$from, $from])
            ->selectRaw('sum(line.signed_minor) as closing_minor')
            ->orderBy('account.code')->orderBy('account.id')->get()
            ->filter(fn ($row): bool => (int) $row->opening_minor !== 0 || (int) $row->debit_minor !== 0 || (int) $row->credit_minor !== 0);

        $totals = [
            'opening_debit' => $accounts->where('opening_minor', '>', 0)->sum('opening_minor'),
            'opening_credit' => -$accounts->where('opening_minor', '<', 0)->sum('opening_minor'),
            'debit' => $accounts->sum('debit_minor'),
            'credit' => $accounts->sum('credit_minor'),
            'closing_debit' => $accounts->where('closing_minor', '>', 0)->sum('closing_minor'),
            'closing_credit' => -$accounts->where('closing_minor', '<', 0)->sum('closing_minor'),
        ];

        return view('staff.loan-ledger.index', compact('from', 'to', 'accounts', 'totals'));
    }

    public function account(Request $request, int $account, LedgerReportData $ledger): View
    {
        [$from, $to] = $ledger->period($request);
        $record = DB::table('chart_accounts')->find($account, ['id', 'code', 'name', 'legacy_id']);
        abort_if($record === null, 404);

        $accountLines = $ledger->lines()->where('line.chart_account_id', $account);
        $opening = (clone $accountLines)
            ->whereRaw("(line.transaction_date < ? OR (line.transaction_date = ? AND line.source_kind = 'opening'))", [$from, $from])
            ->selectRaw('coalesce(sum(line.signed_minor), 0) AS opening_minor');
        $movement = (clone $accountLines)->whereBetween('line.transaction_date', [$from, $to])
            ->whereRaw("NOT (line.transaction_date = ? AND line.source_kind = 'opening')", [$from])
            ->select([
                'line.entry_id', 'line.transaction_date', 'line.source_reference', 'line.source_kind',
                'line.subject_id', 'line.journal_reference', 'line.signed_minor', 'line.posted_at',
            ]);
        $result = DB::query()->fromSub($opening, 'opening')
            ->leftJoinSub($movement, 'movement', fn (JoinClause $join) => $join->whereRaw('true'))
            ->orderBy('movement.transaction_date')->orderByRaw("CASE WHEN movement.source_kind = 'opening' THEN 0 ELSE 1 END")->orderBy('movement.posted_at')->orderBy('movement.source_kind')->orderBy('movement.entry_id')
            ->get(['opening.opening_minor', 'movement.*']);

        $openingBalance = (int) $result->first()->opening_minor;
        $closingBalance = $openingBalance;
        $entries = $result->whereNotNull('entry_id')->each(function ($row) use (&$closingBalance): void {
            $closingBalance += (int) $row->signed_minor;
            $row->balance_minor = $closingBalance;
        });

        return view('staff.loan-ledger.account', compact('record', 'from', 'to', 'entries', 'openingBalance', 'closingBalance'));
    }
}
