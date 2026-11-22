@extends('layout')
@section('title', 'Legacy cutover')
@section('content')
    <div class="page-intro">
        <h1>Legacy cutover</h1>
        <p>Use the same month-end for the legacy reports and verified balances. Review the opening ledger for the following day.</p>
    </div>
    @include('form-errors')
    <section class="panel">
        <form method="GET" action="{{ route('staff.legacy-cutover') }}" class="fields search">
            @if ($snapshots)
                <label for="snapshot">Staged export</label>
                <select id="snapshot" name="snapshot">
                    @foreach ($snapshots as $hash)
                        <option value="{{ $hash }}" @selected($hash === $snapshot)>{{ $hash }}</option>
                    @endforeach
                </select>
            @endif
            <label for="period">Legacy closing month</label>
            <input id="period" type="month" name="period" min="2000-01" max="{{ $lastEndedMonth->format('Y-m') }}" value="{{ $period }}" required>
            <button class="primary">Show cutover status</button>
        </form>
    </section>
    <section class="panel">
        <h2>Resolve source exceptions</h2>
        @if ($snapshot)
            <div class="table-wrap" tabindex="0" role="region" aria-label="Staged legacy records">
                <table>
                    <thead><tr><th scope="col">Record type</th><th scope="col">Staged</th><th scope="col">Rows with unresolved flags</th></tr></thead>
                    <tbody>
                        @foreach (['loans' => 'Loans', 'loanschedules' => 'Scheduled installments', 'repayments' => 'Repayments'] as $table => $label)
                            <tr><th scope="row">{{ $label }}</th><td>{{ number_format($sources->get($table)?->staged ?? 0) }}</td><td>{{ number_format($sources->get($table)?->pending ?? 0) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p><a href="{{ route('staff.loan-corrections.worklist', ['snapshot' => $snapshot]) }}">Download unresolved records (CSV)</a></p>
            <p><a href="{{ route('staff.loan-corrections.index', ['snapshot' => $snapshot]) }}">Review loan corrections and download templates</a></p>
        @else
            <p>No legacy loan snapshot has been staged.</p>
        @endif
    </section>
    <section class="panel">
        <h2>Reconcile and record balances as of {{ $asOf }}</h2>
        <p>Verified legacy loan balances in this snapshot for the selected date: {{ number_format($loans->verified) }} · TZS {{ number_format($loans->principal_minor / 100, 2) }} in principal.</p>
        <p>Current active members with verified savings and shares dated {{ $asOf }}: {{ number_format($members->verified) }} of {{ number_format($members->active) }}.</p>
        <p>Savings TZS {{ number_format($members->savings_minor / 100, 2) }} · Shares TZS {{ number_format($members->shares_minor / 100, 2) }}.</p>
        <p>Check the verified loan count and total against the approved month-end loan report. The staged loan count includes historical loans.</p>
        @if ($snapshot)
            <p><a href="{{ route('staff.loan-reconciliation.index', ['snapshot' => $snapshot, 'as_of' => $asOf]) }}">Compare the legacy loan report</a></p>
            <p><a href="{{ route('staff.verified-legacy-loans.index') }}">Import reviewed loan balances</a></p>
        @endif
        <p><a href="{{ route('staff.verified-balance-import.index') }}">Import reviewed member balances</a></p>
    </section>
    <section class="panel">
        <h2>Review the opening ledger for {{ $openingDate }}</h2>
        @if ($opening)
            <p>Opening journal posted for {{ $opening->effective_on }} · {{ $opening->review_reference }}.</p>
            @if ($opening->effective_on !== $openingDate)
                <p>That journal belongs to a different cutover date.</p>
            @endif
        @else
            <p>No opening journal has been posted.</p>
        @endif
        <p><a href="{{ route('staff.ledger-opening.index') }}">Review the opening journal</a></p>
        <p><a href="{{ route('staff.loan-ledger.index', ['from' => $openingDate, 'to' => $openingDate]) }}">View the trial balance on {{ $openingDate }}</a></p>
    </section>
@endsection
