@extends('layout')
@section('title', 'Financial reports')
@section('content')
    @php($money = fn ($value) => number_format($value / 100, 2))
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-ledger.index', ['from' => $from, 'to' => $to]) }}">SACCOS ledger trial balance</a>
        <p class="print-only">TASAF SACCOS</p>
        <a class="print-hide" href="{{ route('staff.cash-book.index', compact('from', 'to')) }}">Cash book</a>
        <a class="print-hide" href="{{ route('staff.budgets.index', ['year' => substr($to, 0, 4)]) }}">Annual budget</a>
        <a class="print-hide" href="{{ route('staff.assets.index') }}">Asset register</a>
        <h1>Financial reports</h1>
        <p>Posted ledger activity · TZS</p>
    </div>
    @include('staff.loan-ledger.date-filter', ['action' => route('staff.ledger-financial-reports')])

    @if ($other->isNotEmpty() || $totals['difference'] !== 0)
        <section class="panel stacked-panel print-register">
            <h2>Accounts to review</h2>
            <p>Check any accounts outside the five reporting categories before relying on these totals. Financial position difference: TZS {{ $money($totals['difference']) }}.</p>
            @if ($other->isNotEmpty())
                <ul class="review-steps">
                    @foreach ($other as $row)
                        <li>{{ $row->code }} · {{ $row->name }} · {{ $row->category }} · Legacy ID {{ $row->legacy_id }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    <section class="panel stacked-panel print-register">
        <h2>Income and expenses</h2>
        <p>{{ $from }} to {{ $to }}. Uses transaction dates within the selected period. Opening journal values carry into financial position.</p>
        <dl class="summary">
            <div><dt>Income (TZS)</dt><dd>{{ $money($totals['income']) }}</dd></div>
            <div><dt>Expenses (TZS)</dt><dd>{{ $money($totals['expenses']) }}</dd></div>
            <div><dt>Surplus or deficit (TZS)</dt><dd>{{ $money($totals['period_result']) }}</dd></div>
        </dl>
        @include('staff.ledger-financial-report-accounts', ['sections' => [['Income', $income, -1], ['Expenses', $expenses, 1]], 'amountColumn' => 'period_minor'])
    </section>

    <section class="panel stacked-panel print-register">
        <h2>Financial position</h2>
        <p>As of {{ $to }}. Equity includes the accumulated surplus or deficit from income and expense accounts in the ledger.</p>
        <dl class="summary">
            <div><dt>Assets (TZS)</dt><dd>{{ $money($totals['assets']) }}</dd></div>
            <div><dt>Liabilities (TZS)</dt><dd>{{ $money($totals['liabilities']) }}</dd></div>
            <div><dt>Liabilities and equity (TZS)</dt><dd>{{ $money($totals['liabilities_and_equity']) }}</dd></div>
        </dl>
        <p>Capital and equity TZS {{ $money($totals['equity']) }} · Accumulated surplus or deficit TZS {{ $money($totals['accumulated_result']) }}</p>
        @include('staff.ledger-financial-report-accounts', ['sections' => [['Assets', $assets, 1], ['Liabilities', $liabilities, -1], ['Capital and equity', $equity, -1]], 'amountColumn' => 'closing_minor'])
    </section>
@endsection
