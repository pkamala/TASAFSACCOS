@extends('layout')
@section('title', 'Monthly statement drafts')
@section('content')
    @php($money = fn ($amount) => number_format($amount / 100, 2))
    <div class="page-intro">
        <a href="{{ route('staff.loan-batches.index') }}">Payment lists</a>
        <h1>Monthly statement drafts</h1>
        <a href="{{ route('staff.verified-legacy-loans.index') }}">Verified legacy loan balances</a>
        <a href="{{ route('staff.statement-review.index', ['period' => $period]) }}">Review and queue statements</a>
        <p>Draft principal balances, contributions, and savings for active members through {{ $end }}.</p>
    </div>
    <section class="panel">
        @include('form-errors')
        <form method="GET" action="{{ route('staff.statement-drafts.index') }}" class="fields search">
            <label for="period">Closed month</label>
            <input id="period" name="period" type="month" value="{{ $period }}" required>
            <button class="primary">Generate drafts</button>
        </form>
        <p>{{ number_format($ready) }} ready for finance review · {{ number_format($drafts->count() - $ready) }} on hold</p>
        @if ($ready)
            <p><a href="{{ route('staff.statement-drafts.download', ['period' => $period]) }}">Download ready drafts (CSV)</a></p>
        @endif
        <p class="note">Contributions include savings deposits and share purchases credited in the month. Loan balances combine operational principal outstanding with finance-verified legacy principal for the same month-end. Verify recipient addresses and figures before queuing a draft for delivery.</p>
    </section>
    <section class="panel stacked-panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Monthly statement drafts">
            <table data-table-pages>
                <thead><tr><th scope="col">Member</th><th scope="col" class="money">Loan principal (TZS)</th><th scope="col" class="money">Contributions (TZS)</th><th scope="col" class="money">Savings (TZS)</th><th scope="col">Review status</th></tr></thead>
                <tbody>
                    @forelse ($drafts as $row)
                        <tr>
                            <th scope="row" class="register-name">{{ $row->registration_no }} · {{ $row->first_name }} {{ $row->last_name }}</th>
                            @if ($row->hold)
                                <td colspan="3">—</td>
                                <td>{{ $row->hold }}</td>
                            @else
                                <td class="money">{{ $money($row->loan_minor) }}</td><td class="money">{{ $money($row->contribution_minor) }}</td><td class="money">{{ $money($row->savings_minor) }}</td>
                                <td>Ready for finance review</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="5">No active members found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
