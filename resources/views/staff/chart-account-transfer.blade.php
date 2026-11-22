@extends('layout')
@section('title', 'Account balance transfers')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.chart-accounts.show', $original->id) }}">Back to chart account</a>
        <h1>Account balance transfers</h1>
        <p>{{ $original->code }} · {{ $original->name }} (ID {{ $original->id }}) → {{ $replacement->code }} · {{ $replacement->name }} (ID {{ $replacement->id }})</p>
    </div>
    @include('form-errors')
    @if ($original->is_ledger_account && $replacement->is_ledger_account && ! $replacement->retired_at)
        <section class="panel">
            <h2>Prepare transfer</h2>
            <p>The journal moves the full ledger balance on the selected date. Earlier transactions stay on the original account.</p>
            <form method="POST" action="{{ route('staff.chart-accounts.transfer-preview', $original->id) }}" class="fields">
                @csrf
                <label for="transaction-date">Posting date</label>
                <input type="date" id="transaction-date" name="transaction_date" value="{{ old('transaction_date', $preview['date'] ?? $today) }}" max="{{ $today }}" required>
                <label for="reason">Reason</label>
                <textarea id="reason" name="reason" maxlength="500" required>{{ old('reason', $preview['reason'] ?? '') }}</textarea>
                <button class="primary">Preview transfer</button>
            </form>
        </section>
        @if ($preview)
            <section class="panel" id="transfer-preview">
                <h2>Transfer preview</h2>
                <p>{{ $preview['date'] }} · {{ $preview['reason'] }}</p>
                @include('staff.chart-account-transfer-lines', ['signedMinor' => $preview['signed_minor']])
                <form method="POST" action="{{ route('staff.chart-accounts.transfer-post', $original->id) }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $preview['token'] }}">
                    <button class="primary">Post balance transfer</button>
                </form>
            </section>
        @endif
    @endif
    <section class="panel">
        <h2>Posted transfers</h2>
        @forelse ($history as $transfer)
            <p><a href="{{ route('staff.chart-account-transfers.show', $transfer->id) }}">Transfer #{{ $transfer->id }}</a> · {{ $transfer->transaction_date }} · {{ $transfer->actor }}</p>
        @empty
            <p>No transfers posted.</p>
        @endforelse
        @include('staff.pagination', ['page' => $history])
    </section>
@endsection
