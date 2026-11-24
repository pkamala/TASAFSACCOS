<?php

namespace App\Http\Controllers;

use App\BudgetLines;
use App\LedgerReportData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BudgetController extends Controller
{
    public function index(Request $request, LedgerReportData $ledger, BudgetLines $budgets): View
    {
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:1900,9998']]);
        $today = now('Africa/Dar_es_Salaam');
        $year = (int) ($data['year'] ?? $today->year);
        $actuals = $ledger->lines()->where('line.source_kind', '<>', 'opening')
            ->whereBetween('line.transaction_date', ["$year-01-01", min("$year-12-31", $today->toDateString())])
            ->groupBy('line.chart_account_id')
            ->selectRaw('line.chart_account_id, SUM(line.signed_minor) AS actual_minor');
        $accounts = $budgets->accounts()
            ->leftJoin('annual_budget_lines as budget', fn ($join) => $join
                ->on('budget.chart_account_id', '=', 'account.id')->where('budget.year', $year))
            ->leftJoinSub($actuals, 'actual', 'actual.chart_account_id', '=', 'account.id')
            ->orderBy('account.code')->orderBy('account.id')
            ->get(['account.id', 'account.code', 'account.name', 'category.name as category', 'budget.amount_minor', 'actual.actual_minor'])
            ->each(function ($account): void {
                $account->is_income = in_array(strtolower(trim($account->category)), ['income', 'revenue'], true);
                $account->actual_minor = (int) $account->actual_minor * ($account->is_income ? -1 : 1);
            });
        $lines = $accounts->filter(fn ($account): bool => $account->amount_minor !== null || $account->actual_minor !== 0);

        $preview = $request->session()->get(BudgetImportController::SessionKey);
        if (($preview['actor'] ?? null) !== $request->user()->id || $preview['year'] !== $year) {
            $preview = null;
        }
        $importOpen = $request->boolean('import');

        return view('staff.budgets', compact('year', 'accounts', 'lines', 'preview', 'importOpen'));
    }

    public function store(Request $request, BudgetLines $budgets): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:1900,9998'],
            'account' => ['required', 'integer', Rule::exists('chart_accounts', 'id')],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'between:0,999999999999.99'],
        ]);

        DB::transaction(function () use ($request, $budgets, $data): void {
            $budgets->lockYear((int) $data['year']);
            if ($budgets->accounts()->where('account.id', $data['account'])->sharedLock()->first(['account.id']) === null) {
                throw ValidationException::withMessages(['account' => 'Choose an income or expense account.']);
            }

            $budgets->save((int) $data['year'], [[
                'account_id' => $data['account'],
                'amount_minor' => (int) round($data['amount'] * 100),
            ]], $request->user()->id);
        }, 3);

        return redirect()->route('staff.budgets.index', ['year' => $data['year']])->with('status', 'Budget line saved.');
    }
}
