<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CashBookTrialBalancePreviewController extends Controller
{
    public function __invoke(): View
    {
        $source = DB::table('cash_book_review_opening_lines')->orderByDesc('as_of')->orderBy('source_sha256')
            ->first(['source_sha256', 'source_file', 'as_of']);
        $opening = $source === null ? collect() : DB::table('cash_book_review_opening_lines')
            ->where('source_sha256', $source->source_sha256)->orderBy('source_row')->get();
        $accounts = DB::table('chart_accounts')->where('is_ledger_account', true)
            ->get(['id', 'code', 'name', 'retired_at'])->keyBy('id');
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
                    'unmapped' => false,
                ];
                $rows[$key][$line['signed_minor'] > 0 ? 'movement_debit' : 'movement_credit'] += abs($line['signed_minor']);
            }
        }

        $lines = collect($rows)->sortBy([['unmapped', 'asc'], ['code', 'asc'], ['name', 'asc']])->values()
            ->map(function (array $row): array {
                $net = $row['opening_debit'] - $row['opening_credit'] + $row['movement_debit'] - $row['movement_credit'];

                return [...$row, 'preview_debit' => max(0, $net), 'preview_credit' => max(0, -$net)];
            });
        $totals = [];
        foreach (['opening_debit', 'opening_credit', 'movement_debit', 'movement_credit', 'preview_debit', 'preview_credit'] as $column) {
            $totals[$column] = $lines->sum($column);
        }

        return view('staff.cash-book-review.trial-balance', compact('source', 'counts', 'unmapped', 'lines', 'totals'));
    }
}
