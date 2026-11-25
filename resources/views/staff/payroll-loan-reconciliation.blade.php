@extends('layout')
@section('title', 'Payroll loan reconciliation')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-batches.index') }}">Payment lists</a>
        <h1>Payroll loan reconciliation</h1>
        <p>Match payroll deductions to posted loan receipts. Verify the outstanding loan schedule before recording a missing repayment.</p>
    </div>
    @if (session('status')) <p class="status" role="status">{{ session('status') }}</p> @endif
    @include('form-errors')
    <section class="panel stacked-panel">
        <form method="GET" action="{{ route('staff.payroll-loan-reconciliation.index') }}" class="fields search">
            <label for="month">Payroll month</label>
            <input type="month" id="month" name="month" value="{{ $month }}">
            <label for="reconciliation-status">Status</label>
            <select id="reconciliation-status" name="status">
                <option value="open" @selected($status === 'open')>Open</option>
                <option value="reconciled" @selected($status === 'reconciled')>Reconciled</option>
            </select>
            <button class="primary">Filter</button>
        </form>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Payroll loan deductions">
            <table>
                <thead>
                    <tr><th scope="col">Member</th><th scope="col">Source</th><th scope="col">Principal (TZS)</th><th scope="col">Interest (TZS)</th><th scope="col">Total (TZS)</th><th scope="col">Receipt</th></tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <th scope="row">{{ $entry->member_name }} @if ($entry->registration_no) · {{ $entry->registration_no }} @endif</th>
                            <td>
                                {{ \Carbon\CarbonImmutable::parse($entry->payroll_month)->format('M Y') }} · {{ $entry->source_file }}<br>
                                Row {{ $entry->source_row }} · Loan {{ $entry->loan_slot }}
                                @if ($entry->source_loan_reference)<br>Source loan {{ $entry->source_loan_reference }}@endif
                                @if ($entry->source_member_number && $entry->source_member_number !== $entry->registration_no)<br>Source member no. {{ $entry->source_member_number }}@endif
                            </td>
                            <td class="money">{{ $entry->principal_minor === null ? '—' : number_format($entry->principal_minor / 100, 2) }}</td>
                            <td class="money">{{ $entry->interest_minor === null ? '—' : number_format($entry->interest_minor / 100, 2) }}</td>
                            <td class="money">{{ number_format($entry->total_minor / 100, 2) }}</td>
                            <td>
                                @if ($entry->receipt_id)
                                    <a href="{{ route('staff.loan-journal.show', $entry->loan_batch_id) }}">{{ $entry->receipt_reference }}</a>
                                @elseif ($entry->source_issue)
                                    {{ $entry->source_issue }}
                                @else
                                    <details>
                                        <summary>Match posted receipt</summary>
                                        <form method="POST" action="{{ route('staff.payroll-loan-reconciliation.reconcile', $entry->id) }}" class="fields">
                                            @csrf
                                            @method('PATCH')
                                            <label for="member-no-{{ $entry->id }}">Membership number</label>
                                            <input id="member-no-{{ $entry->id }}" name="registration_no" value="{{ $entry->registration_no }}" maxlength="30" required>
                                            <label for="loan-id-{{ $entry->id }}">App loan number</label>
                                            <input id="loan-id-{{ $entry->id }}" type="number" name="loan_id" min="1" required>
                                            <label for="receipt-{{ $entry->id }}">Receipt reference</label>
                                            <input id="receipt-{{ $entry->id }}" name="receipt_reference" maxlength="100" required>
                                            <button class="primary">Reconcile</button>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No {{ $status }} loan deductions for this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $entries])
    </section>
@endsection
