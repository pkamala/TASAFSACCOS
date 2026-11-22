<?php

namespace App;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LedgerReportData
{
    public function lines(): Builder
    {
        $loan = DB::table('loan_journal_lines as line')
            ->join('loan_journal_entries as entry', 'entry.id', '=', 'line.loan_journal_entry_id')
            ->join('loan_journal_postings as posting', 'posting.id', '=', 'entry.loan_journal_posting_id')
            ->selectRaw("line.chart_account_id, line.signed_minor, entry.transaction_date, entry.id AS entry_id,
                entry.source_reference, 'loan' AS source_kind, entry.loan_application_id AS subject_id, posting.loan_batch_id AS journal_reference, posting.posted_at");
        $memberFunds = DB::table('member_fund_journals as journal')
            ->selectRaw("journal.debit_account_id AS chart_account_id, journal.amount_minor AS signed_minor, journal.transaction_date,
                journal.id AS entry_id, journal.source_reference, 'member_fund' AS source_kind,
                journal.member_fund_transaction_id AS subject_id, journal.member_fund_transaction_id AS journal_reference, journal.posted_at");
        $memberFundsCredit = DB::table('member_fund_journals as journal')
            ->selectRaw("journal.credit_account_id AS chart_account_id, -journal.amount_minor AS signed_minor, journal.transaction_date,
                journal.id AS entry_id, journal.source_reference, 'member_fund' AS source_kind,
                journal.member_fund_transaction_id AS subject_id, journal.member_fund_transaction_id AS journal_reference, journal.posted_at");

        $reversals = DB::table('ledger_journal_reversal_lines as line')
            ->join('ledger_journal_reversals as reversal', 'reversal.id', '=', 'line.ledger_journal_reversal_id')
            ->selectRaw("line.chart_account_id, line.signed_minor, reversal.transaction_date,
                line.id AS entry_id, line.source_reference, 'reversal' AS source_kind,
                line.subject_id, reversal.id AS journal_reference, reversal.reversed_at AS posted_at");

        $opening = DB::table('ledger_opening_lines as line')
            ->join('ledger_opening_journals as journal', 'journal.id', '=', 'line.ledger_opening_journal_id')
            ->selectRaw("line.chart_account_id, line.signed_minor, journal.effective_on AS transaction_date,
                line.id AS entry_id, journal.review_reference AS source_reference, 'opening' AS source_kind,
                journal.id AS subject_id, journal.id AS journal_reference, journal.posted_at");

        $transferOut = DB::table('chart_account_transfers as transfer')
            ->selectRaw("transfer.original_account_id AS chart_account_id, -transfer.signed_minor AS signed_minor, transfer.transaction_date,
                transfer.id AS entry_id, transfer.reason AS source_reference, 'account_transfer' AS source_kind,
                transfer.id AS subject_id, transfer.id AS journal_reference, transfer.posted_at");
        $transferIn = DB::table('chart_account_transfers as transfer')
            ->selectRaw("transfer.replacement_account_id AS chart_account_id, transfer.signed_minor, transfer.transaction_date,
                transfer.id AS entry_id, transfer.reason AS source_reference, 'account_transfer' AS source_kind,
                transfer.id AS subject_id, transfer.id AS journal_reference, transfer.posted_at");

        $general = DB::table('general_journal_lines as line')
            ->join('general_journals as journal', 'journal.id', '=', 'line.general_journal_id')
            ->selectRaw("line.chart_account_id, line.signed_minor, journal.transaction_date, line.id AS entry_id,
                journal.source_reference, 'general' AS source_kind, journal.id AS subject_id, journal.id AS journal_reference, journal.posted_at");

        return DB::query()->fromSub($loan->unionAll($memberFunds)->unionAll($memberFundsCredit)->unionAll($reversals)
            ->unionAll($opening)->unionAll($transferOut)->unionAll($transferIn)->unionAll($general), 'line');
    }

    public function period(Request $request): array
    {
        $today = now('Africa/Dar_es_Salaam');
        $data = $request->validate([
            'from' => ['required_with:to', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$today->toDateString()],
            'to' => ['required_with:from', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$today->toDateString()],
        ]);

        return [$data['from'] ?? $today->copy()->startOfMonth()->toDateString(), $data['to'] ?? $today->toDateString()];
    }
}
