<?php

namespace App\Http\Controllers;

use App\LedgerReportData;
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
        $accounts = $this->balances($ledger, $from, $to);
        $columns = self::Columns;
        $totals = $this->totals($accounts);

        return view('staff.cash-book', compact('from', 'to', 'accounts', 'columns', 'totals'));
    }

    public function export(Request $request, LedgerReportData $ledger): StreamedResponse
    {
        [$from, $to] = $ledger->period($request);
        $accounts = $this->balances($ledger, $from, $to);
        $totals = $this->totals($accounts);

        return response()->streamDownload(function () use ($accounts, $totals): void {
            $stream = fopen('php://output', 'w');
            $amounts = fn (array $row): array => array_map(
                fn (string $column): string => number_format($row[$column] / 100, 2, '.', ''),
                array_keys(self::Columns),
            );

            fputcsv($stream, ['Account ID', 'Code', 'Account', ...array_values(self::Columns)], escape: '');
            foreach ($accounts as $account) {
                fputcsv($stream, [
                    $account->id, $this->csvText($account->code), $this->csvText($account->name),
                    ...$amounts((array) $account),
                ], escape: '');
            }
            fputcsv($stream, ['', '', 'Total', ...$amounts($totals)], escape: '');
            fclose($stream);
        }, "cash-book-{$from}-to-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
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

    private function totals(Collection $accounts): array
    {
        $totals = [];
        foreach (array_keys(self::Columns) as $column) {
            $totals[$column] = $accounts->sum($column);
        }

        return $totals;
    }

    private function csvText(string $value): string
    {
        return preg_match('/^[\x00-\x20]*[=+@-]|^[\t\r\n]/', $value) ? "'".$value : $value;
    }
}
