@extends('layout')
@section('title', 'Chart account')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.accounts.index') }}">Chart of accounts</a>
        <h1>{{ $record->code }} · {{ $record->name }}</h1>
        <p>{{ $record->category }} · {{ $record->type }} · {{ $record->retired_at ? 'Retired' : 'Active' }}</p>
        <p>Account ID {{ $record->id }} @if ($record->legacy_id) · Legacy ID {{ $record->legacy_id }} @endif</p>
        <a href="{{ route('staff.loan-ledger.account', $record->id) }}">View ledger activity</a>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel">
        <h2>Account history</h2>
        @if ($record->creator)
            <p>Created by {{ $record->creator }} · {{ \Carbon\CarbonImmutable::parse($record->created_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT · {{ $record->creation_reason }}</p>
        @else
            <p>Imported from the legacy chart of accounts.</p>
        @endif
        @if ($record->replaces_account_id)
            <p>Replaces <a href="{{ route('staff.chart-accounts.show', $record->replaces_account_id) }}">account {{ $record->replaces_account_id }}</a>.</p>
        @endif
        @if ($record->retired_at)
            <p>Retired by {{ $record->retiree }} · {{ \Carbon\CarbonImmutable::parse($record->retired_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT · {{ $record->retirement_reason }}</p>
        @endif
        @if ($replacement)
            <p>Replacement <a href="{{ route('staff.chart-accounts.show', $replacement->id) }}">{{ $replacement->code }} · {{ $replacement->name }}</a>.</p>
            <p><a href="{{ route('staff.chart-accounts.transfer', $record->id) }}">Balance transfers</a></p>
        @endif
    </section>
    @if ($record->is_ledger_account)
        <section class="panel stacked-panel">
            <h2>Cash book classification</h2>
            <form method="POST" action="{{ route('staff.chart-accounts.cash-book', $record->id) }}" class="fields">
                @csrf
                @method('PATCH')
                <label for="cash-book-account">Include this cash or bank account in the report</label>
                <select id="cash-book-account" name="is_cash_book_account">
                    <option value="0" @selected(! $record->cash_book_account_id)>No</option>
                    <option value="1" @selected($record->cash_book_account_id)>Yes</option>
                </select>
                <label for="bank-account">Include this account in bank reconciliation</label>
                <select id="bank-account" name="is_bank_account">
                    <option value="0" @selected(! $record->is_bank_account)>No</option>
                    <option value="1" @selected($record->is_bank_account)>Yes</option>
                </select>
                <button class="primary">Save classification</button>
            </form>
            <p><a href="{{ route('staff.cash-book.index') }}">Open cash book</a></p>
        </section>
    @endif
    @unless ($record->retired_at)
        <section class="panel stacked-panel admin-profile">
            <h2>Manage account</h2>
            <p><a href="{{ route('staff.chart-accounts.replacement', $record->id) }}">Create a replacement</a></p>
            <p>Retiring removes the account from new operational postings. Existing journals and balances keep this account.</p>
            <form method="POST" action="{{ route('staff.chart-accounts.retire', $record->id) }}" class="fields">
                @csrf
                <label for="retire-reason">Reason for retirement</label>
                <textarea id="retire-reason" name="reason" maxlength="500" required></textarea>
                <button class="primary">Retire account</button>
            </form>
        </section>
    @endunless
@endsection
