<?php

namespace App\Http\Controllers;

use App\TablePage;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LoanTransactionsReportController extends Controller
{
    public function __invoke(Request $request): View
    {
        $today = now('Africa/Dar_es_Salaam');
        $input = $request->validate([
            'from' => ['required_with:to', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$today->toDateString()],
            'to' => ['required_with:from', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$today->toDateString()],
            'kind' => ['nullable', 'in:all,disbursements,repayments'],
        ]);
        $from = $input['from'] ?? $today->copy()->startOfMonth()->toDateString();
        $to = $input['to'] ?? $today->toDateString();
        $kind = $input['kind'] ?? 'all';
        $disbursements = $kind === 'repayments' ? collect() : $this->disbursements($from, $to);
        $repayments = $kind === 'disbursements' ? collect() : $this->repayments($from, $to);
        $totals = [
            'cash' => $disbursements->sum('cash_amount_minor'),
            'receipts' => $repayments->sum('amount_minor'),
            'principal' => $repayments->sum('principal_minor'),
            'interest' => $repayments->sum('interest_minor'),
        ];

        return view('staff.loan-transactions-report', compact(
            'from', 'to', 'kind', 'disbursements', 'repayments', 'totals',
        ) + ['generatedAt' => $today->format('d M Y H:i')]);
    }

    private function disbursements(string $from, string $to): Collection
    {
        return DB::table('loan_disbursements as transfer')
            ->join('loan_applications as loan', 'loan.id', '=', 'transfer.loan_application_id')
            ->join('members as member', 'member.id', '=', 'loan.member_id')
            ->tap(TablePage::memberStatus('member.status'))
            ->join('users as recorder', 'recorder.id', '=', 'transfer.recorded_by')
            ->whereBetween('transfer.transferred_on', [$from, $to])
            ->orderBy('transfer.transferred_on')->orderBy('loan.id')
            ->get([
                'loan.id as application_id', 'loan.member_id', 'member.registration_no', 'member.first_name', 'member.last_name',
                'transfer.transferred_on', 'transfer.transfer_reference', 'transfer.cash_amount_minor',
                'transfer.loan_batch_id', 'recorder.name as recorder',
            ]);
    }

    private function repayments(string $from, string $to): Collection
    {
        $allocated = DB::table('loan_repayment_allocations as allocation')
            ->join('loan_repayment_receipts as receipt', 'receipt.id', '=', 'allocation.loan_repayment_receipt_id')
            ->whereBetween('receipt.received_on', [$from, $to])
            ->groupBy('allocation.loan_repayment_receipt_id')
            ->selectRaw('allocation.loan_repayment_receipt_id, sum(allocation.principal_minor) as principal_minor, sum(allocation.interest_minor) as interest_minor');

        return DB::table('loan_repayment_receipts as receipt')
            ->join('loan_applications as loan', 'loan.id', '=', 'receipt.loan_application_id')
            ->join('members as member', 'member.id', '=', 'loan.member_id')
            ->tap(TablePage::memberStatus('member.status'))
            ->join('users as recorder', 'recorder.id', '=', 'receipt.recorded_by')
            ->leftJoinSub($allocated, 'allocated', 'allocated.loan_repayment_receipt_id', '=', 'receipt.id')
            ->whereBetween('receipt.received_on', [$from, $to])
            ->orderBy('receipt.received_on')->orderBy('receipt.id')
            ->get([
                'loan.id as application_id', 'loan.member_id', 'member.registration_no', 'member.first_name', 'member.last_name',
                'receipt.received_on', 'receipt.receipt_reference', 'receipt.amount_minor', 'receipt.loan_batch_id',
                'allocated.principal_minor', 'allocated.interest_minor', 'recorder.name as recorder',
            ]);
    }
}
