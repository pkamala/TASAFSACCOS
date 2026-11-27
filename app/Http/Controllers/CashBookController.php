<?php

namespace App\Http\Controllers;

use App\LedgerReportData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashBookController extends Controller
{
    private const Columns = [
        'opening_minor' => 'Opening balance',
        'cutover_minor' => 'Opening entries after start',
        'receipts_minor' => 'Receipts (Dr)',
        'payments_minor' => 'Payments (Cr)',
        'closing_minor' => 'Closing balance',
    ];

    public function index(Request $request, LedgerReportData $ledger): View
    {
        [$from, $to] = $ledger->period($request);
        [$accounts, $columns, $workbook] = $this->report($request, $ledger, $from, $to);
        $totals = $this->totals($accounts, $columns);
        $latestMonth = DB::table('cash_book_monthly_balances')->max('month');

        return view('staff.cash-book', compact('from', 'to', 'accounts', 'columns', 'totals', 'workbook', 'latestMonth'));
    }

    public function export(Request $request, LedgerReportData $ledger): StreamedResponse
    {
        [$from, $to] = $ledger->period($request);
        [$accounts, $columns, $workbook] = $this->report($request, $ledger, $from, $to);
        $totals = $this->totals($accounts, $columns);
        $prefix = $workbook === [] ? 'cash-book' : 'cash-book-workbook';

        return response()->streamDownload(function () use ($accounts, $totals, $columns): void {
            $stream = fopen('php://output', 'w');
            $amounts = fn (array $row): array => array_map(
                fn (string $column): string => number_format($row[$column] / 100, 2, '.', ''),
                array_keys($columns),
            );

            fputcsv($stream, ['Account ID', 'Code', 'Account', ...array_values($columns)], escape: '');
            foreach ($accounts as $account) {
                fputcsv($stream, [
                    $account->id, $this->csvText($account->code), $this->csvText($account->name),
                    ...$amounts((array) $account),
                ], escape: '');
            }
            fputcsv($stream, ['', '', 'Total', ...$amounts($totals)], escape: '');
            fclose($stream);
        }, "{$prefix}-{$from}-to-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{Collection, array<string, string>, list<string>}
     */
    private function report(Request $request, LedgerReportData $ledger, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        if ($request->query('source') === 'ledger' || $start->day !== 1 || $end->day !== $end->daysInMonth) {
            return [$this->balances($ledger, $from, $to), self::Columns, []];
        }

        $rows = DB::table('cash_book_monthly_balances as month')
            ->join('chart_accounts as account', 'account.id', '=', 'month.chart_account_id')
            ->whereBetween('month.month', [$from, $to])
            ->orderBy('account.code')->orderBy('month.month')
            ->get(['account.id', 'account.code', 'account.name', 'month.month', 'month.opening_minor',
                'month.receipts_minor', 'month.payments_minor', 'month.closing_minor', 'month.source_file']);
        $monthCount = ($end->year - $start->year) * 12 + $end->month - $start->month + 1;
        $byAccount = $rows->groupBy('id');
        if ($byAccount->isEmpty() || $byAccount->contains(fn (Collection $months): bool => $months->count() !== $monthCount)) {
            return [$this->balances($ledger, $from, $to), self::Columns, []];
        }

        $accounts = $byAccount->map(function (Collection $months): object {
            $first = $months->first();
            $opening = $first->opening_minor;
            $receipts = $months->sum('receipts_minor');
            $payments = $months->sum('payments_minor');
            $closing = $months->last()->closing_minor;

            return (object) [
                'id' => $first->id, 'code' => $first->code, 'name' => $first->name,
                'opening_minor' => $opening, 'receipts_minor' => $receipts, 'payments_minor' => $payments,
                'difference_minor' => $closing - ($opening + $receipts - $payments), 'closing_minor' => $closing,
            ];
        })->values();

        return [$accounts, [
            'opening_minor' => 'Opening balance',
            'receipts_minor' => 'Receipts (Dr)',
            'payments_minor' => 'Payments (Cr)',
            'difference_minor' => 'Workbook difference',
            'closing_minor' => 'Closing balance',
        ], $rows->pluck('source_file')->unique()->values()->all()];
    }

    private function balances(LedgerReportData $ledger, string $from, string $to): Collection
    {
        return DB::table('chart_accounts as account')
            ->where('account.is_ledger_account', true)
            ->join('cash_book_accounts as cash', 'cash.chart_account_id', '=', 'account.id')
            ->leftJoinSub(
                $ledger->lines()->where('line.transaction_date', '<=', $to),
                'movement',
                fn (JoinClause $join) => $join->on('movement.chart_account_id', '=', 'account.id'),
            )
            ->groupBy('account.id')
            ->select('account.id', 'account.code', 'account.name')
            ->selectRaw("coalesce(sum(movement.signed_minor) FILTER (WHERE movement.transaction_date < ? OR (movement.transaction_date = ? AND movement.source_kind = 'opening')), 0) AS opening_minor", [$from, $from])
            ->selectRaw("coalesce(sum(movement.signed_minor) FILTER (WHERE movement.transaction_date > ? AND movement.source_kind = 'opening'), 0) AS cutover_minor", [$from])
            ->selectRaw("coalesce(sum(movement.signed_minor) FILTER (WHERE movement.transaction_date >= ? AND movement.source_kind <> 'opening' AND movement.signed_minor > 0), 0) AS receipts_minor", [$from])
            ->selectRaw("coalesce(-sum(movement.signed_minor) FILTER (WHERE movement.transaction_date >= ? AND movement.source_kind <> 'opening' AND movement.signed_minor < 0), 0) AS payments_minor", [$from])
            ->selectRaw('coalesce(sum(movement.signed_minor), 0) AS closing_minor')
            ->orderBy('account.code')->orderBy('account.name')->orderBy('account.id')
            ->get();
    }

    private function totals(Collection $accounts, array $columns): array
    {
        $totals = [];
        foreach (array_keys($columns) as $column) {
            $totals[$column] = $accounts->sum($column);
        }

        return $totals;
    }

    private function csvText(string $value): string
    {
        return preg_match('/^[\x00-\x20]*[=+@-]|^[\t\r\n]/', $value) ? "'".$value : $value;
    }
}
