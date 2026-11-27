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
@endsection
