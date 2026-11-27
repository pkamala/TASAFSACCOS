@extends('layout')
@section('title', 'Payment list')
@section('content')
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-batches.index') }}">Payment lists</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>{{ match ($record->kind) { 'disbursement' => 'Disbursement checklist', 'savings_payment' => 'Savings-funded repayment', default => 'Monthly TASAF payroll list' } }} {{ $record->id }}</h1>
        <p>{{ ucfirst($record->status) }} @if ($record->period) · {{ substr($record->period, 0, 7) }} · TASAF Accounts @endif</p>
        @if ($record->reviewed_by)<button class="primary print-hide" type="button" onclick="window.print()">Print list</button>@endif
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    @if ($payrollTotals !== null)
        <dl class="summary">
            <div><dt>Confirmed savings (TZS)</dt><dd>{{ number_format($payrollTotals['savings'] / 100, 2) }}</dd></div>
            <div><dt>Confirmed shares (TZS)</dt><dd>{{ number_format($payrollTotals['shares'] / 100, 2) }}</dd></div>
            <div><dt>Confirmed loan repayments (TZS)</dt><dd>{{ number_format($payrollTotals['loans'] / 100, 2) }}</dd></div>
        </dl>
        @if ($pendingLoanDeductions > 0)
            <p class="print-hide">
                <a href="{{ route('staff.payroll-loan-reconciliation.index', ['month' => substr($record->period, 0, 7)]) }}">{{ number_format($pendingLoanDeductions) }} {{ Str::plural('loan deduction', $pendingLoanDeductions) }} awaiting reconciliation</a>
            </p>
        @endif
    @endif
    @if ($record->status === 'confirmed' && $record->kind === 'repayment')
        @can('manage-members')
            @if ($confirmedTotal === 0)
                <p>No deductions were received. There are no payment journals to post.</p>
            @else
                <p class="print-hide"><a href="{{ route('staff.payroll-journals.show', $record->id) }}">Review and post payment list journals</a></p>
            @endif
        @endcan
    @endif
    @if ($record->status === 'confirmed' && in_array($record->kind, ['disbursement', 'savings_payment'], true) && collect($items)->pluck('loan_id')->filter()->isNotEmpty())
        @can('manage-members')
            <p class="print-hide"><a href="{{ route('staff.loan-journal.show', $record->id) }}">View or prepare loan journal</a></p>
        @endcan
    @endif
    <section class="panel print-register">
        @if ($deductionReview !== null)
            <h2>Confirmed payroll deductions</h2>
            <p>TASAF Accounts reference {{ $deductionReview['reference'] }} · Actual total TZS {{ number_format($confirmedTotal / 100, 2) }}</p>
            @if ($deductionReview['note'])<p>{{ $deductionReview['note'] }}</p>@endif
        @endif
        <div class="table-wrap"><table data-table-pages>
            <thead><tr><th scope="col">Transaction</th><th scope="col">Member</th><th scope="col" class="money">{{ $deductionReview ? 'Planned amount' : 'Amount' }} (TZS)</th>@if ($record->kind !== 'disbursement')<th scope="col">Allocations (TZS)</th>@endif</tr></thead>
            <tbody>
                @foreach ($items as $item)
                    @php
                        $member = $members->get($item['member_id']);
                        $rowKey = isset($item['loan_id']) ? 'loan:'.$item['loan_id'] : 'fund:'.$item['member_id'];
                    @endphp
                    <tr data-member-status="{{ $member->status }}"><th scope="row">
                        @if (isset($item['loan_id']))
                            <a href="{{ route('staff.loan-applications.show', [$item['member_id'], $item['loan_id']]) }}">Loan {{ $item['loan_id'] }}</a>
                        @else
                            Contributions
                        @endif
                    </th><td>{{ $member->registration_no }} · {{ $member->first_name }} {{ $member->last_name }}</td><td class="money">
                        {{ number_format($item['amount'] / 100, 2) }}
                        @if ($topUp = $topUpTransfers[$item['loan_id'] ?? 0] ?? null)
                            <div>Clears loan {{ $topUp['loan'] }} · {{ number_format($topUp['payoff'] / 100, 2) }}</div>
                            <div>{{ $record->status === 'confirmed' ? 'Net transfer' : 'Estimated net transfer' }} · {{ number_format($topUp['net'] / 100, 2) }}</div>
                        @endif
                    </td>
                        @if ($record->kind !== 'disbursement')
                            <td>
                                @if (isset($item['funds']))
                                    @foreach (array_filter($item['funds']) as $kind => $amount)
                                        @php($label = $kind === 'savings_deposit' ? 'Savings' : 'Shares')
                                        @php($transaction = $fundTransactions->get($item['member_id'])?->get($kind))
                                        <div>
                                            {{ $label }} {{ number_format($amount / 100, 2) }}
                                            @if ($transaction)
                                                @can('manage-members')
                                                    <a class="print-hide" href="{{ route('staff.member-fund-journals.show', $transaction->id) }}" aria-label="View {{ strtolower($label) }} journal for {{ $member->registration_no }}">Journal</a>
                                                @endcan
                                            @endif
                                        </div>
                                    @endforeach
                                @else
                                    @foreach ($item['allocations'] as $number => $allocation)
                                        <div>Month {{ $number }} @isset($item['overdue_installments'][$number]) · Overdue since {{ $item['overdue_installments'][$number] }} @endisset · Principal {{ number_format($allocation['principal'] / 100, 2) }} · Interest {{ number_format($allocation['interest'] / 100, 2) }}</div>
                                    @endforeach
                                @endif
                                @foreach ($deductionDifferences->get($rowKey, []) as $difference)
                                    <div>{{ $difference['label'] }} · {{ $difference['status'] }} · Planned {{ number_format($difference['planned'] / 100, 2) }} · Actual {{ number_format($difference['actual'] / 100, 2) }}</div>
                                @endforeach
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><th scope="row" colspan="2">{{ $netTransferTotal === null ? 'Total' : 'Total principal' }}</th><td class="money">{{ number_format(array_sum(array_column($items, 'amount')) / 100, 2) }}</td>@if ($record->kind !== 'disbursement')<td></td>@endif</tr>
                @if ($netTransferTotal !== null)
                    <tr><th scope="row" colspan="2">{{ $record->status === 'confirmed' ? 'Total net bank transfers' : 'Estimated total net bank transfers' }}</th><td class="money">{{ number_format($netTransferTotal / 100, 2) }}</td></tr>
                @endif
            </tfoot>
        </table></div>
        <p>Prepared by {{ $actors->get($record->prepared_by) }} · {{ $record->prepared_at }}</p>
        @if ($record->reviewed_by)<p>{{ ucfirst($record->status === 'declined' ? 'declined' : 'approved') }} by {{ $actors->get($record->reviewed_by) }} · {{ $record->reviewed_at }} · {{ $record->review_note }}</p>@endif
        @if ($record->confirmed_by)<p>Confirmed by {{ $actors->get($record->confirmed_by) }} · {{ $record->confirmed_at }} · Effective {{ $record->effective_date }}</p>@endif
        @if ($record->kind === 'disbursement' && $record->status === 'approved')
            <p class="print-only">Bank stamp and signature ___________________________ &nbsp;&nbsp; Date __________________</p>
        @endif
        @if ($record->evidence_id)<p class="print-hide"><a href="{{ route('staff.loan-batches.checklist', $record->id) }}">Download bank-stamped checklist (PDF)</a></p>@endif
    </section>
    @if ($record->status === 'prepared')
        @can('review-loans')
            <section class="panel stacked-panel print-hide"><h2>Review list</h2>
                <form class="fields search" method="POST" action="{{ route('staff.loan-batches.review', $record->id) }}">
                    @csrf
                    <label for="decision">Decision</label>
                    <select id="decision" name="decision" required><option value="">Choose decision</option><option value="approved">Approve</option><option value="declined">Decline</option></select>
                    <label for="review_note">Review note</label>
                    <textarea id="review_note" name="review_note" maxlength="500" required></textarea>
                    <button class="primary">Record decision</button>
                </form>
            </section>
        @endcan
    @endif
    @if ($record->status === 'approved')
        @can('manage-members')
            @if ($record->kind === 'repayment')
                <section class="panel stacked-panel print-hide">
                    <h2>Confirm payroll</h2>
                    <p><a href="{{ route('staff.payroll-deductions.show', $record->id) }}">Review actual payroll deductions</a></p>
                </section>
            @else
                <section class="panel stacked-panel print-hide">
                    <h2>Confirm completion</h2>
                    <form class="fields search" method="POST" action="{{ route('staff.loan-batches.confirm', $record->id) }}" enctype="multipart/form-data">
                        @csrf
                        <label for="effective_date">Actual transfer date</label>
                        <input id="effective_date" name="effective_date" type="date" required>
                        <label for="bank_checklist">Bank-stamped checklist (PDF)</label>
                        <input type="file" id="bank_checklist" name="bank_checklist" accept="application/pdf,.pdf" required>
                        @foreach ($items as $item)
                            <label for="ref-{{ $item['loan_id'] }}">Transfer reference for loan {{ $item['loan_id'] }}</label>
                            <input id="ref-{{ $item['loan_id'] }}" name="references[{{ $item['loan_id'] }}]" maxlength="100" required>
                            @isset($topUpTransfers[$item['loan_id']])
                                <label for="net-{{ $item['loan_id'] }}">Actual net bank transfer for loan {{ $item['loan_id'] }} (TZS)</label>
                                <input id="net-{{ $item['loan_id'] }}" name="net_transfers[{{ $item['loan_id'] }}]" value="{{ old('net_transfers.'.$item['loan_id']) }}" type="number" min="0.01" step="0.01" required>
                            @endisset
                        @endforeach
                        <button class="primary">Confirm approved list</button>
                    </form>
                </section>
            @endif
        @endcan
    @endif
@endsection
