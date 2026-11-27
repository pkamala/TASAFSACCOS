@extends('layout')
@section('title', 'Reporting overview')
@section('content')
    @php($money = fn ($minor) => number_format($minor / 100, 2))

    <div class="page-intro">
        <h1>Reporting overview</h1>
    </div>

    <section class="panel stacked-panel">
        <h2>Loan portfolio</h2>
        <p>Financed amounts at disbursement, including charges. Review the loan register for individual balances and repayment histories.</p>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Loan portfolio">
            <table>
                <thead>
                    <tr><th scope="col">Category</th><th scope="col">Disbursed loans</th><th scope="col">Active loans</th><th scope="col">Financed amount (TZS)</th></tr>
                </thead>
                <tbody>
                    @forelse ($portfolio as $category)
                        <tr>
                            <th scope="row">{{ $category->category ?? 'Uncategorized' }}</th>
                            <td>{{ number_format($category->disbursed_count) }}</td>
                            <td>{{ number_format($category->active_count) }}</td>
                            <td>{{ $money($category->financed_minor) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No disbursed loans recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p><a href="{{ route('staff.loan-register') }}">Open loan register</a></p>
    </section>

    <section class="panel stacked-panel">
        <h2>Payroll loan reconciliation</h2>
        <p>Pending deductions still need to be linked to a posted repayment. Select a month to review its open deductions.</p>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Payroll loan reconciliation">
            <table>
                <thead>
                    <tr><th scope="col">Payroll month</th><th scope="col">Reconciled</th><th scope="col">Reconciled (TZS)</th><th scope="col">Pending</th><th scope="col">Pending (TZS)</th></tr>
                </thead>
                <tbody>
                    @forelse ($payroll as $month)
                        <tr>
                            <th scope="row"><a href="{{ route('staff.payroll-loan-reconciliation.index', ['month' => substr($month->payroll_month, 0, 7)]) }}">{{ \Carbon\CarbonImmutable::parse($month->payroll_month)->format('M Y') }}</a></th>
                            <td>{{ number_format($month->reconciled_count) }}</td>
                            <td>{{ $money($month->reconciled_minor) }}</td>
                            <td>{{ number_format($month->pending_count) }}</td>
                            <td>{{ $money($month->pending_minor) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No payroll loan deductions recorded.</td></tr>
                    @endforelse
                </tbody>
                @if ($payroll->isNotEmpty())
                    <tfoot>
                        <tr>
                            <th scope="row">Total</th>
                            <td>{{ number_format($payroll->sum('reconciled_count')) }}</td>
                            <td>{{ $money($payroll->sum('reconciled_minor')) }}</td>
                            <td>{{ number_format($payroll->sum('pending_count')) }}</td>
                            <td>{{ $money($payroll->sum('pending_minor')) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>
@endsection
