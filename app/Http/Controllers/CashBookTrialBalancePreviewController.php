<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CashBookTrialBalancePreviewController extends Controller
{
    private const CategoryOrder = ['Assets', 'Liabilities', 'Equity', 'Income', 'Expenses', 'Other accounts', 'Unmapped opening lines'];

    public function __invoke(): View
    {
        $source = DB::table('cash_book_review_opening_lines')->orderByDesc('as_of')->orderBy('source_sha256')
            ->first(['source_sha256', 'source_file', 'as_of']);
        $opening = $source === null ? collect() : DB::table('cash_book_review_opening_lines')
            ->where('source_sha256', $source->source_sha256)->orderBy('source_row')->get();
        $accounts = DB::table('chart_accounts as account')->where('account.is_ledger_account', true)
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->join('account_categories as category', 'category.id', '=', 'type.account_category_id')
            ->get(['account.id', 'account.code', 'account.name', 'account.retired_at', 'category.name as category'])->keyBy('id');
        $activeCodes = $accounts->whereNull('retired_at')->groupBy('code');
        $entries = $source === null ? collect() : DB::table('cash_book_review_entries as entry')
            ->where('entry.source_sha256', $source->source_sha256)
            ->whereIn('entry.status', ['approved', 'posted'])
            ->whereNotExists(fn ($reversal) => $reversal->selectRaw('1')->from('ledger_journal_reversals as reversal')
                ->whereColumn('reversal.general_journal_id', 'entry.general_journal_id'))
            ->get(['entry.status', 'entry.approved_lines']);
        $counts = $entries->countBy('status');
        $unmapped = 0;
        $rows = [];
        foreach ($opening as $line) {
            $matches = $activeCodes->get($line->account_code, collect());
            $account = $matches->count() === 1 ? $matches->first() : null;
            $key = $account?->id ?? 'opening-'.$line->source_row;
            $unmapped += (int) ($account === null);
            $rows[$key] ??= [
                'code' => $line->account_code, 'name' => $account?->name ?? $line->description,
                'opening_debit' => 0, 'opening_credit' => 0, 'movement_debit' => 0, 'movement_credit' => 0,
                'category' => $this->category($account?->category),
                'unmapped' => $account === null,
            ];
            $rows[$key][$line->signed_minor > 0 ? 'opening_debit' : 'opening_credit'] += abs($line->signed_minor);
        }

        foreach ($entries as $entry) {
            foreach (json_decode($entry->approved_lines, true, flags: JSON_THROW_ON_ERROR) as $line) {
                $account = $accounts->get($line['chart_account_id']);
                $key = $account->id;
                $rows[$key] ??= [
                    'code' => $account->code, 'name' => $account->name,
                    'opening_debit' => 0, 'opening_credit' => 0, 'movement_debit' => 0, 'movement_credit' => 0,
                    'category' => $this->category($account->category),
                    'unmapped' => false,
                ];
                $rows[$key][$line['signed_minor'] > 0 ? 'movement_debit' : 'movement_credit'] += abs($line['signed_minor']);
            }
        }

        $categoryOrder = array_flip(self::CategoryOrder);
        $lines = collect($rows)->sortBy([
            fn (array $left, array $right): int => $categoryOrder[$left['category']] <=> $categoryOrder[$right['category']],
            ['code', 'asc'],
            ['name', 'asc'],
        ])->values()
            ->map(function (array $row): array {
                $net = $row['opening_debit'] - $row['opening_credit'] + $row['movement_debit'] - $row['movement_credit'];

                return [...$row, 'preview_debit' => max(0, $net), 'preview_credit' => max(0, -$net)];
            });
        $totals = [];
        foreach (['opening_debit', 'opening_credit', 'movement_debit', 'movement_credit', 'preview_debit', 'preview_credit'] as $column) {
            $totals[$column] = $lines->sum($column);
        }

        $groups = $lines->groupBy('category');

        return view('staff.cash-book-review.trial-balance', compact('source', 'counts', 'unmapped', 'groups', 'totals'));
    }

    private function category(?string $name): string
    {
        return match (strtolower(trim($name ?? ''))) {
            '' => 'Unmapped opening lines',
            'asset', 'assets' => 'Assets',
            'liability', 'liabilities' => 'Liabilities',
            'capital', 'equity' => 'Equity',
            'income', 'revenue' => 'Income',
            'expense', 'expenses' => 'Expenses',
            default => 'Other accounts',
        };
    }
}
