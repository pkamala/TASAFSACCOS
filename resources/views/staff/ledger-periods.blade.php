@extends('layout')
@section('title', 'Ledger periods')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-ledger.index') }}">SACCOS ledger trial balance</a>
        <h1>Ledger periods</h1>
        <p>{{ $from }} to {{ $to }} · {{ $closed ? 'Closed' : 'Open' }}</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <div class="profile-grid">
        <section class="panel">
            <h2>Period checklist</h2>
            <form method="GET" action="{{ route('staff.ledger-periods.index') }}" class="fields search">
                <label for="month">Ledger month</label>
                <input type="month" id="month" name="month" value="{{ $month }}" required>
                <button class="primary">Show period</button>
            </form>
            <ul class="review-steps">
                <li>{{ number_format($unposted['loan_disbursements']) }} confirmed disbursements awaiting a journal</li>
                <li>{{ number_format($unposted['loan_repayments']) }} confirmed repayments awaiting a journal</li>
                <li>{{ number_format($unposted['member_funds']) }} savings and share transactions awaiting a journal</li>
            </ul>
            <p><a href="{{ route('staff.loan-transactions', ['from' => $from, 'to' => $reportTo]) }}">Review confirmed loan transactions</a></p>
            <p><a href="{{ route('staff.loan-ledger.index', ['from' => $from, 'to' => $reportTo]) }}">Review trial balance for this period</a></p>
        </section>
        <section class="panel">
            <h2>{{ $closed ? 'Reopen period' : 'Close period' }}</h2>
            @if ($closed || $mayClose)
                <p>{{ $closed ? 'Reopening allows transactions and journals dated in this month.' : 'Closing prevents transactions, journals, and reversals dated in this month.' }}</p>
                <form method="POST" action="{{ route('staff.ledger-periods.record') }}" class="fields">
                    @csrf
                    <input type="hidden" name="month" value="{{ $month }}">
                    <input type="hidden" name="action" value="{{ $closed ? 'reopen' : 'close' }}">
                    <label for="reason">Reason or reconciliation reference</label>
                    <textarea id="reason" name="reason" maxlength="500" rows="4" required>{{ is_string(old('reason')) ? old('reason') : '' }}</textarea>
                    <button class="primary">{{ $closed ? 'Reopen month' : 'Close month' }}</button>
                </form>
            @else
                <p>{{ array_sum($unposted) ? 'Post the confirmed transactions before closing.' : 'This month can be closed after it ends.' }}</p>
            @endif
            <p class="note">This checklist covers the operational loan and member fund ledger. Use the opening journal for verified cutover balances.</p>
        </section>
    </div>
    <section class="panel stacked-panel">
        <h2>Period history</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Ledger period history">
            <table>
                <thead><tr><th scope="col">Action</th><th scope="col">Recorded by</th><th scope="col">Recorded at</th><th scope="col">Reason</th></tr></thead>
                <tbody>
                    @forelse ($history as $row)
                        <tr><th scope="row">{{ ucfirst($row->action) }}</th><td>{{ $row->actor }}</td><td>{{ \Carbon\CarbonImmutable::parse($row->recorded_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</td><td class="register-name">{{ $row->reason }}</td></tr>
                    @empty
                        <tr><td colspan="4">No period changes recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $history])
    </section>
@endsection
