<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class JournalPosting
{
    public function __construct(private ChartPostingAccounts $chart) {}

    public function writeLoan(int $batch, int $actor, array $lines): void
    {
        $posting = DB::table('loan_journal_postings')->insertGetId([
            'loan_batch_id' => $batch, 'posted_by' => $actor, 'posted_at' => now(),
        ]);
        foreach (collect($lines)->groupBy('loan_application_id') as $loan => $journal) {
            $first = $journal->first();
            $entry = DB::table('loan_journal_entries')->insertGetId([
                'loan_journal_posting_id' => $posting, 'loan_application_id' => $loan,
                'source_reference' => $first->source_reference, 'transaction_date' => $first->transaction_date,
            ]);

            DB::table('loan_journal_lines')->insert($journal->map(fn ($line): array => [
                'loan_journal_entry_id' => $entry,
                'chart_account_id' => $line->chart_account_id,
                'signed_minor' => $line->signed_minor,
            ])->all());
        }
    }

    public function writeGeneral(int $actor, string $token, array $journal, array $lines): int
    {
        $id = DB::table('general_journals')->insertGetId([
            ...$journal, 'preview_token' => $token, 'line_count' => count($lines),
            'posted_by' => $actor, 'posted_at' => now(),
        ]);
        DB::table('general_journal_lines')->insert(array_map(
            static fn (array $line): array => [...$line, 'general_journal_id' => $id], $lines,
        ));

        return $id;
    }

    public function writeFund(int $transaction, int $actor, array $journal): void
    {
        DB::table('member_fund_journals')->insert([
            ...$journal, 'member_fund_transaction_id' => $transaction, 'posted_by' => $actor, 'posted_at' => now(),
        ]);
    }

    public function loanLines(stdClass $batch, array $accounts): array
    {
        $selected = array_values(array_filter($accounts));
        if (count($selected) !== count(array_unique($selected))
            || ! $this->chart->available($selected)) {
            $this->invalid('Select distinct posting accounts from the chart of accounts.');
        }

        $expected = collect(PayrollDeductions::confirmedItems($batch))
            ->filter(fn (array $item): bool => isset($item['loan_id']))->keyBy('loan_id');
        $disbursement = $batch->kind === 'disbursement';
        $payoffs = DB::table('loan_repayment_receipts as receipt')->where('receipt.loan_batch_id', $batch->id)
            ->join('loan_repayment_allocations as allocation', 'allocation.loan_repayment_receipt_id', '=', 'receipt.id')
            ->groupBy('receipt.loan_application_id')
            ->selectRaw('receipt.loan_application_id, sum(allocation.principal_minor) as principal, sum(allocation.interest_minor) as interest');
        $transactions = $disbursement
            ? DB::table('loan_disbursements as transfer')->where('transfer.loan_batch_id', $batch->id)
                ->join('loan_applications as loan', 'loan.id', '=', 'transfer.loan_application_id')
                ->leftJoinSub($payoffs, 'payoff', 'payoff.loan_application_id', '=', 'loan.top_up_loan_id')
                ->selectRaw('loan.id AS loan_id, loan.top_up_loan_id, transfer.transferred_on AS dated, transfer.transfer_reference AS reference, transfer.cash_amount_minor AS cash')
                ->selectRaw('coalesce(payoff.principal, 0) AS retired_principal, coalesce(payoff.interest, 0) AS collected_interest')
                ->selectRaw('loan.insurance_minor + loan.processing_fee_minor + loan.other_charges_minor AS offset')
                ->orderBy('loan.id')->get()
            : DB::table('loan_repayment_receipts as receipt')->where('receipt.loan_batch_id', $batch->id)
                ->join('loan_repayment_allocations as allocation', 'allocation.loan_repayment_receipt_id', '=', 'receipt.id')
                ->groupBy('receipt.id')
                ->selectRaw('receipt.loan_application_id AS loan_id, receipt.received_on AS dated, receipt.receipt_reference AS reference, receipt.amount_minor AS cash')
                ->selectRaw('sum(allocation.principal_minor) AS principal, sum(allocation.interest_minor) AS offset')
                ->orderBy('receipt.loan_application_id')->get();

        if ($transactions->isEmpty() || $transactions->count() !== $expected->count()
            || $transactions->pluck('loan_id')->duplicates()->isNotEmpty()) {
            $this->invalid('The confirmed transactions do not match the payment list.');
        }

        $lines = [];
        foreach ($transactions as $transaction) {
            $cash = (int) $transaction->cash;
            $offset = (int) $transaction->offset;
            $retiredPrincipal = (int) ($transaction->retired_principal ?? 0);
            $collectedInterest = (int) ($transaction->collected_interest ?? 0);
            $gross = $cash + $retiredPrincipal + $collectedInterest;
            $principal = $disbursement ? $gross + $offset - $retiredPrincipal : (int) $transaction->principal;
            if ((int) ($expected->get($transaction->loan_id)['amount'] ?? 0) !== $gross || $cash <= 0
                || (($transaction->top_up_loan_id ?? null) !== null && $retiredPrincipal + $collectedInterest <= 0)
                || $offset < 0 || $principal < 0 || (! $disbursement && $cash !== $principal + $offset)) {
                $this->invalid("Loan {$transaction->loan_id} has inconsistent confirmed amounts.");
            }
            if ($offset > 0 && $accounts['offset'] === null) {
                $this->invalid('Select the offset account for the financed charges or collected interest.');
            }

            if ($collectedInterest > 0 && ($accounts['top_up_interest'] ?? null) === null) {
                $this->invalid('Select the interest income account for the original loan payoff.');
            }

            $movement = [
                'cash' => $disbursement ? -$cash : $cash,
                'principal' => $disbursement ? $principal : -$principal,
                'offset' => -$offset,
                'top_up_interest' => -$collectedInterest,
            ];
            foreach (array_filter($movement) as $key => $signedMinor) {
                $lines[] = (object) [
                    'loan_application_id' => $transaction->loan_id,
                    'transaction_date' => $transaction->dated,
                    'source_reference' => $transaction->reference,
                    'chart_account_id' => $accounts[$key],
                    'signed_minor' => $signedMinor,
                ];
            }
        }

        return $lines;
    }

    public function fundJournal(stdClass $record, int $cash, int $fund, ?int $withholdingAccount = null): array
    {
        $withholding = $record->withholding_minor ?? 0;
        $accounts = [$cash, $fund];
        if ($withholding > 0) {
            if ($withholdingAccount === null) {
                $this->invalid('Select the withholding payable account.');
            }
            $accounts[] = $withholdingAccount;
        }
        if (count($accounts) !== count(array_unique($accounts)) || ! $this->chart->available($accounts)) {
            $this->invalid('Select distinct posting accounts from the chart of accounts.');
        }

        $withdrawal = $record->kind === 'savings_withdrawal';

        return [
            'debit_account_id' => $withdrawal ? $fund : $cash,
            'credit_account_id' => $withdrawal ? $cash : $fund,
            'amount_minor' => abs($record->signed_minor),
            'withholding_minor' => $withholding,
            'withholding_account_id' => $withholding > 0 ? $withholdingAccount : null,
            'transaction_date' => $record->effective_on,
            'source_reference' => $record->reference,
        ];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['journal' => $message]);
    }
}
