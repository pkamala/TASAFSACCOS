@extends('layout')
@section('title', 'Payment list')
@section('content')
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-batches.index') }}">Payment lists</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>{{ $record->kind === 'disbursement' ? 'Disbursement checklist' : 'Monthly TASAF payroll list' }} {{ $record->id }}</h1>
        <p>{{ ucfirst($record->status) }} @if ($record->period) · {{ substr($record->period, 0, 7) }} · TASAF Accounts @endif</p>
        @if ($record->reviewed_by)<button class="primary print-hide" type="button" onclick="window.print()">Print list</button>@endif
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    @if ($record->status === 'confirmed' && collect($items)->pluck('loan_id')->filter()->isNotEmpty())
        @can('manage-members')
            <p class="print-hide"><a href="{{ route('staff.loan-journal.show', $record->id) }}">View or prepare loan journal</a></p>
        @endcan
    @endif
    <section class="panel print-register">
        <div class="table-wrap"><table data-table-pages>
            <thead><tr><th scope="col">Transaction</th><th scope="col">Member</th><th scope="col" class="money">Amount (TZS)</th>@if ($record->kind === 'repayment')<th scope="col">Allocations (TZS)</th>@endif</tr></thead>
            <tbody>
                @foreach ($items as $item)
                    @php($member = $members->get($item['member_id']))
                    <tr><th scope="row">
                        @if (isset($item['loan_id']))
                            <a href="{{ route('staff.loan-applications.show', [$item['member_id'], $item['loan_id']]) }}">Loan {{ $item['loan_id'] }}</a>
                        @else
                            Savings and shares
                        @endif
                    </th><td>{{ $member->registration_no }} · {{ $member->first_name }} {{ $member->last_name }}</td><td class="money">{{ number_format($item['amount'] / 100, 2) }}</td>
                        @if ($record->kind === 'repayment')
                            <td>
                                @if (isset($item['funds']))
                                    <div>Savings {{ number_format($item['funds']['savings_deposit'] / 100, 2) }}</div>
                                    <div>Shares {{ number_format($item['funds']['share_purchase'] / 100, 2) }}</div>
                                @else
                                    @foreach ($item['allocations'] as $number => $allocation)
                                        <div>Month {{ $number }} · Principal {{ number_format($allocation['principal'] / 100, 2) }} · Interest {{ number_format($allocation['interest'] / 100, 2) }}</div>
                                    @endforeach
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr><th scope="row" colspan="2">Total</th><td class="money">{{ number_format(array_sum(array_column($items, 'amount')) / 100, 2) }}</td>@if ($record->kind === 'repayment')<td></td>@endif</tr></tfoot>
        </table></div>
        <p>Prepared by {{ $actors->get($record->prepared_by) }} · {{ $record->prepared_at }}</p>
        @if ($record->reviewed_by)<p>{{ ucfirst($record->status === 'declined' ? 'declined' : 'approved') }} by {{ $actors->get($record->reviewed_by) }} · {{ $record->reviewed_at }} · {{ $record->review_note }}</p>@endif
        @if ($record->confirmed_by)<p>Confirmed by {{ $actors->get($record->confirmed_by) }} · {{ $record->confirmed_at }} · Effective {{ $record->effective_date }}</p>@endif
        @if ($record->kind === 'disbursement' && $record->status === 'approved')
            <p class="print-only">Bank stamp and signature ___________________________ &nbsp;&nbsp; Date __________________</p>
        @endif
        @if ($record->evidence_id)<p class="print-hide"><a href="{{ route('staff.loan-batches.checklist', $record->id) }}">Download bank-stamped checklist (PDF)</a></p>@endif
    </section>
    @can('manage-members')
        @if ($fundTransactions->isNotEmpty())
            <section class="panel stacked-panel print-hide">
                <h2>Confirmed savings and share transactions</h2>
                <ul class="template-list">
                    @foreach ($fundTransactions as $transaction)
                        <li><a href="{{ route('staff.member-fund-journals.show', $transaction->id) }}">{{ $members->get($transaction->member_id)->registration_no }} · {{ ucfirst(str_replace('_', ' ', $transaction->kind)) }} · TZS {{ number_format($transaction->signed_minor / 100, 2) }}</a></li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endcan
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
            <section class="panel stacked-panel print-hide"><h2>Confirm completion</h2>
                <form class="fields search" method="POST" action="{{ route('staff.loan-batches.confirm', $record->id) }}" enctype="multipart/form-data">
                    @csrf
                    <label for="effective_date">Actual transfer or repayment date</label>
                    <input id="effective_date" name="effective_date" type="date" required>
                    @if ($record->kind === 'disbursement')
                        <label for="bank_checklist">Bank-stamped checklist (PDF)</label>
                        <input type="file" id="bank_checklist" name="bank_checklist" accept="application/pdf,.pdf" required>
                        @foreach ($items as $item)
                            <label for="ref-{{ $item['loan_id'] }}">Transfer reference for loan {{ $item['loan_id'] }}</label>
                            <input id="ref-{{ $item['loan_id'] }}" name="references[{{ $item['loan_id'] }}]" maxlength="100" required>
                        @endforeach
                    @else
                        <label for="payroll_reference">Confirmed TASAF Accounts payroll reference</label>
                        <input id="payroll_reference" name="payroll_reference" maxlength="100" required>
                    @endif
                    <button class="primary">Confirm approved list</button>
                </form>
            </section>
        @endcan
    @endif
@endsection
