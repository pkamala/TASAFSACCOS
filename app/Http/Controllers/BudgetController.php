<?php

namespace App\Http\Controllers;

use App\LedgerReportData;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BudgetController extends Controller
{
    public function index(Request $request, LedgerReportData $ledger): View
    {
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:1900,9998']]);
        $today = now('Africa/Dar_es_Salaam');
        $year = (int) ($data['year'] ?? $today->year);
        $actuals = $ledger->lines()->where('line.source_kind', '<>', 'opening')
            ->whereBetween('line.transaction_date', ["$year-01-01", min("$year-12-31", $today->toDateString())])
            ->groupBy('line.chart_account_id')
            ->selectRaw('line.chart_account_id, SUM(line.signed_minor) AS actual_minor');
        $accounts = $this->accounts()
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

        return view('staff.budgets', compact('year', 'accounts', 'lines'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:1900,9998'],
            'account' => ['required', 'integer', Rule::exists('chart_accounts', 'id')],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'between:0,999999999999.99'],
        ]);

        if (! $this->accounts()->where('account.id', $data['account'])->exists()) {
            throw ValidationException::withMessages(['account' => 'Choose an income or expense account.']);
        }

        DB::table('annual_budget_lines')->upsert([[
            'year' => $data['year'],
            'chart_account_id' => $data['account'],
            'amount_minor' => (int) round($data['amount'] * 100),
            'updated_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['year', 'chart_account_id'], ['amount_minor', 'updated_by', 'updated_at']);

        return redirect()->route('staff.budgets.index', ['year' => $data['year']])->with('status', 'Budget line saved.');
    }

    private function accounts(): Builder
    {
        return DB::table('chart_accounts as account')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->join('account_categories as category', 'category.id', '=', 'type.account_category_id')
            ->whereIn(DB::raw('lower(trim(category.name))'), ['income', 'revenue', 'expense', 'expenses']);
    }
}
