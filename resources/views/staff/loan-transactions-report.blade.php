@extends('layout')
@section('title', 'Loan transactions report')
@section('content')
    @php($money = fn ($amount) => $amount === null ? '—' : number_format($amount / 100, 2))
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-batches.index') }}">Payment lists</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>Loan transactions report</h1>
        <p>{{ $from }} to {{ $to }} · Generated {{ $generatedAt }} EAT</p>
    </div>
    <section class="panel print-hide">
        @include('form-errors')
        <form class="fields search" method="GET" action="{{ route('staff.loan-transactions') }}">
            <label for="from">From</label>
            <input id="from" name="from" type="date" value="{{ $from }}" required>
            <label for="to">To</label>
            <input id="to" name="to" type="date" value="{{ $to }}" required>
            <label for="kind">Transactions</label>
            <select id="kind" name="kind">
                @foreach (['all' => 'All transactions', 'disbursements' => 'Disbursements', 'repayments' => 'Repayments'] as $value => $label)
                    <option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="primary">Run report</button>
        </form>
        <button class="primary" type="button" onclick="window.print()">Print report</button>
    </section>

    <section class="panel stacked-panel print-register" aria-labelledby="totals">
        <h2 id="totals">Confirmed totals (TZS)</h2>
        <dl class="summary">
            @if ($kind !== 'repayments')
                <div><dt>Cash disbursed</dt><dd>{{ $money($totals['cash']) }}</dd></div>
            @endif
            @if ($kind !== 'disbursements')
                <div><dt>Repayments received</dt><dd>{{ $money($totals['receipts']) }}</dd></div>
                <div><dt>Principal repaid</dt><dd>{{ $money($totals['principal']) }}</dd></div>
                <div><dt>Interest collected</dt><dd>{{ $money($totals['interest']) }}</dd></div>
            @endif
        </dl>
        <p class="note">Includes recorded transfers and receipts for new loans by actual transaction date. Legacy staging and payment lists awaiting confirmation are outside this report.</p>
    </section>

    @foreach (['disbursements' => $disbursements, 'repayments' => $repayments] as $section => $transactions)
        @if ($kind === 'all' || $kind === $section)
            <section class="panel stacked-panel print-register" aria-labelledby="{{ $section }}">
                <h2 id="{{ $section }}">{{ ucfirst($section) }} · {{ number_format($transactions->count()) }}</h2>
                <div class="table-wrap" tabindex="0" role="region" aria-label="{{ ucfirst($section) }}">
                    <table>
                        <thead><tr>
                            <th scope="col">Date</th><th scope="col">Loan</th><th scope="col">Member</th><th scope="col">Reference</th>
                            <th scope="col" class="money">Amount (TZS)</th>
                            @if ($section === 'repayments')
                                <th scope="col" class="money">Principal (TZS)</th><th scope="col" class="money">Interest (TZS)</th>
                            @endif
                            <th scope="col">Payment list</th><th scope="col">Recorded by</th>
                        </tr></thead>
                        <tbody>
                            @forelse ($transactions as $row)
                                <tr>
                                    <td>{{ $row->transferred_on ?? $row->received_on }}</td>
                                    <th scope="row"><a href="{{ route('staff.loan-applications.show', [$row->member_id, $row->application_id]) }}">{{ $row->application_id }}</a></th>
                                    <td class="register-name">{{ $row->registration_no }} · {{ $row->first_name }} {{ $row->last_name }}</td>
                                    <td>{{ $row->transfer_reference ?? $row->receipt_reference }}</td>
                                    <td class="money">{{ $money($row->cash_amount_minor ?? $row->amount_minor) }}</td>
                                    @if ($section === 'repayments')
                                        <td class="money">{{ $money($row->principal_minor) }}</td><td class="money">{{ $money($row->interest_minor) }}</td>
                                    @endif
                                    <td>@if ($row->loan_batch_id)<a href="{{ route('staff.loan-batches.show', $row->loan_batch_id) }}">{{ $row->loan_batch_id }}</a>@else — @endif</td>
                                    <td>{{ $row->recorder }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $section === 'repayments' ? 9 : 7 }}">No confirmed transactions for this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endforeach
@endsection
