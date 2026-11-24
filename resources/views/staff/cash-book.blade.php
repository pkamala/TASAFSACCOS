@extends('layout')
@section('title', 'Cash book')
@section('content')
    @php($money = fn ($value) => number_format($value / 100, 2))
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-ledger.index', compact('from', 'to')) }}">Trial balance</a>
        <a class="print-hide" href="{{ route('staff.ledger-financial-reports', compact('from', 'to')) }}">Financial reports</a>
        @if ($to >= '2000-01-01')
            <a class="print-hide" href="{{ route('staff.ledger-periods.index', ['month' => substr($to, 0, 7)]) }}">Month-end checklist</a>
        @endif
        <p class="print-only">TASAF SACCOS</p>
        <a class="print-hide" href="{{ route('staff.bank-reconciliation.index') }}">Bank reconciliation</a>
        <h1>Cash book</h1>
        <a class="print-hide" href="{{ route('staff.cash-book.transactions.create') }}">Record past transaction</a>
        <p>{{ $from }} to {{ $to }} · TZS</p>
        <p>Posted movements for designated cash and bank accounts. Transfers between these accounts appear in both receipts and payments.</p>
    </div>
    @include('staff.loan-ledger.date-filter', ['action' => route('staff.cash-book.index')])

    <section class="panel stacked-panel print-register">
        <h2>Cash and bank balances</h2>
        <p class="print-hide"><a href="{{ route('staff.accounts.index') }}">Manage accounts in the chart</a>
            @if ($accounts->isNotEmpty()) · <a href="{{ route('staff.cash-book.export', compact('from', 'to')) }}">Download CSV</a> @endif
        </p>
        @if ($accounts->isEmpty())
            <p>Open a ledger account in the chart and include it in the cash book to start reporting.</p>
        @else
            <p>Select an account to review its running balance and open the underlying journals. Use Bank reconciliation to compare the closing balance with the statement before closing the month.</p>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Cash and bank balances">
                <table>
                    <thead><tr><th scope="col">Account</th>
                        @foreach ($columns as $label)<th class="money" scope="col">{{ $label }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($accounts as $account)
                            <tr>
                                <th scope="row"><a href="{{ route('staff.loan-ledger.account', ['account' => $account->id, 'from' => $from, 'to' => $to]) }}">{{ $account->code }} · {{ $account->name }}</a></th>
                                @foreach ($columns as $column => $label)<td class="money">{{ $money($account->$column) }}</td>@endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><th scope="row">Total</th>
                        @foreach ($totals as $amount)<td class="money">{{ $money($amount) }}</td>@endforeach
                    </tr></tfoot>
                </table>
            </div>
        @endif
    </section>
@endsection
