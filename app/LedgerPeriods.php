<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerPeriods
{
    public function requireOpenDates(array $dates, string $field): void
    {
        DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['ledger-cutover']);
        $cutover = DB::table('ledger_opening_journals')->value('effective_on');
        if ($cutover !== null && collect($dates)->contains(fn (string $date): bool => $date < $cutover)) {
            throw ValidationException::withMessages([$field => 'The date is before the ledger cutover on '.$cutover.'.']);
        }

        $periods = array_unique(array_map(static fn (string $date): string => substr($date, 0, 7).'-01', $dates));
        sort($periods);
        foreach ($periods as $period) {
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['ledger-period:'.$period]);
            if ($this->closed($period)) {
                throw ValidationException::withMessages([$field => 'Ledger period '.substr($period, 0, 7).' is closed.']);
            }
        }
    }

    public function closed(string $period): bool
    {
        return DB::table('ledger_period_events')->where('period', $period)->orderByDesc('id')->value('action') === 'closed';
    }

    public function unposted(string $from, string $to): array
    {
        $loanTransfers = DB::table('loan_disbursements as transfer')->whereBetween('transfer.transferred_on', [$from, $to])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('loan_journal_postings as posting')
                ->whereColumn('posting.loan_batch_id', 'transfer.loan_batch_id'))->count();
        $loanRepayments = DB::table('loan_repayment_receipts as receipt')->whereBetween('receipt.received_on', [$from, $to])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('loan_journal_postings as posting')
                ->whereColumn('posting.loan_batch_id', 'receipt.loan_batch_id'))->count();
        $funds = DB::table('member_fund_transactions as transaction')->whereBetween('transaction.effective_on', [$from, $to])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('member_fund_journals as journal')
                ->whereColumn('journal.member_fund_transaction_id', 'transaction.id'))->count();

        return ['loan_disbursements' => $loanTransfers, 'loan_repayments' => $loanRepayments, 'member_funds' => $funds];
    }
}
