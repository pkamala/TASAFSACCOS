<?php

namespace App\Http\Controllers;

use App\LedgerReportData;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LedgerFinancialReportsController extends Controller
{
    public function __invoke(Request $request, LedgerReportData $ledger): View
    {
        [$from, $to] = $ledger->period($request);
        $accounts = $ledger->lines()->where('line.transaction_date', '<=', $to)
            ->join('chart_accounts as account', 'account.id', '=', 'line.chart_account_id')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->join('account_categories as category', 'category.id', '=', 'type.account_category_id')
            ->groupBy('account.id', 'category.name')
            ->select(['account.id', 'account.code', 'account.name', 'account.legacy_id', 'category.name as category'])
            ->selectRaw("coalesce(sum(line.signed_minor) FILTER (WHERE line.transaction_date >= ? AND line.source_kind <> 'opening'), 0) AS period_minor", [$from])
            ->selectRaw('sum(line.signed_minor) AS closing_minor')
            ->orderBy('account.code')->orderBy('account.id')->get()
            ->groupBy(static fn ($row): string => match (strtolower(trim($row->category))) {
                'asset', 'assets' => 'assets',
                'liability', 'liabilities' => 'liabilities',
                'capital', 'equity' => 'equity',
                'income', 'revenue' => 'income',
                'expense', 'expenses' => 'expenses',
                default => 'other',
            });
        $assets = $accounts->get('assets', collect());
        $liabilities = $accounts->get('liabilities', collect());
        $equity = $accounts->get('equity', collect());
        $income = $accounts->get('income', collect());
        $expenses = $accounts->get('expenses', collect());
        $other = $accounts->get('other', collect())->filter(fn ($row): bool => (int) $row->period_minor !== 0 || (int) $row->closing_minor !== 0);
        $totals = [
            'income' => -$income->sum('period_minor'),
            'expenses' => $expenses->sum('period_minor'),
            'assets' => $assets->sum('closing_minor'),
            'liabilities' => -$liabilities->sum('closing_minor'),
            'equity' => -$equity->sum('closing_minor'),
            'accumulated_result' => -$income->sum('closing_minor') - $expenses->sum('closing_minor'),
        ];
        $totals['period_result'] = $totals['income'] - $totals['expenses'];
        $totals['liabilities_and_equity'] = $totals['liabilities'] + $totals['equity'] + $totals['accumulated_result'];
        $totals['difference'] = $totals['assets'] - $totals['liabilities_and_equity'];

        return view('staff.ledger-financial-reports', compact(
            'from', 'to', 'assets', 'liabilities', 'equity', 'income', 'expenses', 'other', 'totals',
        ));
    }
}
