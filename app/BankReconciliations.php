<?php

namespace App;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

class BankReconciliations
{
    public const Reconciled = 'Reconciled';

    public function __construct(private LedgerReportData $ledger) {}

    public function accounts(): Collection
    {
        return DB::table('cash_book_accounts as cash')->join('chart_accounts as account', 'account.id', '=', 'cash.chart_account_id')
            ->where('cash.is_bank_account', true)->where('account.is_ledger_account', true)
            ->orderBy('account.code')->orderBy('account.name')->orderBy('account.id')
            ->get(['account.id', 'account.code', 'account.name', 'account.retired_at'])->keyBy('id');
    }

    public function lines(array $accounts, string $end): Collection
    {
        return $this->ledger->lines()->whereIntegerInRaw('line.chart_account_id', $accounts)
            ->where('line.transaction_date', '<=', $end)
            ->orderBy('line.transaction_date')->orderBy('line.source_kind')->orderBy('line.entry_id')
            ->get(['line.chart_account_id', 'line.transaction_date', 'line.source_kind', 'line.entry_id', 'line.source_reference', 'line.signed_minor'])
            ->groupBy('chart_account_id');
    }

    public function latest(string $period): Collection
    {
        return DB::table('bank_reconciliations')->where('period', $period)
            ->selectRaw('DISTINCT ON (chart_account_id) chart_account_id, id, ledger_sha256, statement_minor, book_minor, outstanding_deposits_minor, outstanding_payments_minor')
            ->orderBy('chart_account_id')->orderByDesc('id')->get()->keyBy('chart_account_id');
    }

    public function fingerprint(Collection $lines): string
    {
        return hash('sha256', json_encode($lines->values()->all(), JSON_THROW_ON_ERROR));
    }

    public function candidates(Collection $lines): Collection
    {
        return $lines->where('source_kind', '!=', 'opening')
            ->keyBy(fn (stdClass $line): string => $line->source_kind.':'.$line->entry_id);
    }

    public function difference(stdClass $review): int
    {
        return $review->book_minor - $review->outstanding_deposits_minor + $review->outstanding_payments_minor - $review->statement_minor;
    }

    public function periodStatuses(string $period, string $end): Collection
    {
        $accounts = $this->accounts();

        return $this->statuses($accounts, $this->lines($accounts->keys()->all(), $end), $this->latest($period), $period, $end);
    }

    public function statuses(Collection $accounts, Collection $lines, Collection $latest, string $period, string $end): Collection
    {
        return $accounts->map(function (stdClass $account) use ($lines, $latest, $period, $end): ?array {
            $activity = $lines->get($account->id, collect());
            $review = $latest->get($account->id);
            if ($review === null && $account->retired_at !== null
                && substr($account->retired_at, 0, 10) < $period
                && (int) $activity->sum('signed_minor') === 0
                && ! $activity->contains(fn (stdClass $line): bool => $line->transaction_date >= $period && $line->transaction_date <= $end)) {
                return null;
            }

            $difference = $review === null ? null : $this->difference($review);

            return [
                'account' => $account,
                'status' => match (true) {
                    $review === null => 'Awaiting review',
                    ! hash_equals($review->ledger_sha256, $this->fingerprint($activity)) => 'Ledger changed',
                    $difference === 0 => self::Reconciled,
                    default => 'Difference to resolve',
                },
                'difference' => $difference,
            ];
        })->filter();
    }
}
