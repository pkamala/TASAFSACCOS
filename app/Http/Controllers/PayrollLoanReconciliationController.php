<?php

namespace App\Http\Controllers;

use App\TablePage;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PayrollLoanReconciliationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:open,reconciled'],
        ]);
        $month = $filters['month'] ?? '';
        $status = $filters['status'] ?? 'open';
        $entries = DB::table('payroll_loan_reconciliations as deduction')
            ->leftJoin('members as member', 'member.id', '=', 'deduction.member_id')
            ->leftJoin('loan_repayment_receipts as receipt', 'receipt.id', '=', 'deduction.receipt_id')
            ->whereNull('deduction.receipt_id', not: $status === 'reconciled')
            ->when($month !== '', fn ($query) => $query->where('deduction.payroll_month', $month.'-01'))
            ->orderBy('deduction.payroll_month')->orderBy('deduction.source_row')->orderBy('deduction.loan_slot')->orderBy('deduction.id')
            ->tap(TablePage::memberStatus('member.status'))
            ->tap(TablePage::search(['deduction.payroll_month', 'deduction.source_file', 'deduction.source_row', 'deduction.member_name', 'deduction.source_member_number', 'deduction.source_loan_reference', 'member.registration_no', 'receipt.receipt_reference']))
            ->paginate(TablePage::size(), ['deduction.*', 'member.registration_no', 'receipt.receipt_reference', 'receipt.loan_batch_id']);

        return view('staff.payroll-loan-reconciliation', compact('entries', 'month', 'status'));
    }

    public function reconcile(Request $request, int $deduction): RedirectResponse
    {
        $input = $request->validate([
            'registration_no' => ['required', 'string', 'max:30'],
            'loan_id' => ['required', 'integer', 'min:1'],
            'receipt_reference' => ['required', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($input, $request, $deduction): void {
            $entry = DB::table('payroll_loan_reconciliations')->lockForUpdate()->find($deduction);
            abort_if($entry === null, 404);
            if ($entry->receipt_id !== null) {
                $this->invalid('This deduction is already reconciled.');
            }
            if ($entry->source_issue !== null || $entry->total_minor <= 0) {
                $this->invalid('Obtain a corrected source line before reconciling this deduction.');
            }

            $monthEnd = CarbonImmutable::parse($entry->payroll_month)->endOfMonth()->toDateString();
            $receipt = DB::table('loan_repayment_receipts as receipt')
                ->join('loan_applications as loan', 'loan.id', '=', 'receipt.loan_application_id')
                ->join('members as member', 'member.id', '=', 'loan.member_id')
                ->where('member.registration_no', strtoupper($input['registration_no']))
                ->where('loan.id', $input['loan_id'])
                ->where('receipt.receipt_reference', strtoupper($input['receipt_reference']))
                ->whereBetween('receipt.received_on', [$entry->payroll_month, $monthEnd])
                ->lock('for update of receipt')->first(['receipt.*', 'loan.member_id']);
            if ($receipt === null || ($entry->member_id !== null && $entry->member_id !== $receipt->member_id)) {
                $this->invalid('Select a receipt for this member, loan and payroll month.');
            }
            if (DB::table('payroll_loan_reconciliations')->where('receipt_id', $receipt->id)->exists()) {
                $this->invalid('This receipt is already linked to a payroll deduction.');
            }

            $amounts = DB::table('loan_repayment_allocations')->where('loan_repayment_receipt_id', $receipt->id)
                ->selectRaw('sum(principal_minor) as principal, sum(interest_minor) as interest')->first();
            if ($receipt->amount_minor !== $entry->total_minor
                || ($entry->principal_minor !== null && $entry->principal_minor !== (int) $amounts->principal)
                || ($entry->interest_minor !== null && $entry->interest_minor !== (int) $amounts->interest)) {
                $this->invalid('The receipt total, principal or interest differs from the payroll deduction.');
            }

            $posting = DB::table('loan_journal_postings')->where('loan_batch_id', $receipt->loan_batch_id)->lockForUpdate()->first();
            if ($posting === null
                || DB::table('ledger_journal_reversals')->where('loan_journal_posting_id', $posting->id)->exists()
                || ! DB::table('loan_journal_entries')->where('loan_journal_posting_id', $posting->id)
                    ->where('loan_application_id', $receipt->loan_application_id)->where('transaction_date', $receipt->received_on)
                    ->where('source_reference', $receipt->receipt_reference)->exists()) {
                $this->invalid('The receipt needs a posted loan journal before this deduction can be reconciled.');
            }

            DB::table('payroll_loan_reconciliations')->where('id', $entry->id)->update([
                'member_id' => $receipt->member_id, 'receipt_id' => $receipt->id,
                'reconciled_by' => $request->user()->id, 'reconciled_at' => now(),
            ]);
        }, 3);

        return back()->with('status', 'Loan deduction reconciled to its posted receipt.');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['receipt_reference' => $message]);
    }
}
