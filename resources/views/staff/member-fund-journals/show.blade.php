@extends('layout')
@section('title', 'Savings and share journal')
@section('content')
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.member-funds.index', $record->member_id) }}">Member savings and shares</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>{{ $posting ? 'Posted member fund journal '.$posting->id : 'Prepare member fund journal' }}</h1>
        <p>{{ $record->registration_no }} · {{ $record->first_name }} {{ $record->last_name }}</p>
        <p>{{ ucfirst(str_replace('_', ' ', $record->kind)) }} · {{ $record->reference }} · {{ $record->effective_on }} · TZS {{ number_format(abs($record->signed_minor) / 100, 2) }}</p>
        @if ($record->loan_batch_id)<p class="print-hide"><a href="{{ route('staff.loan-batches.show', $record->loan_batch_id) }}">TASAF payroll list {{ $record->loan_batch_id }}</a></p>@endif
        @if ($posting)
            <p>Posted by {{ $posting->actor }} on {{ \Carbon\CarbonImmutable::parse($posting->posted_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT.</p>
            <button class="primary print-hide" type="button" onclick="window.print()">Print journal</button>
            @if ($reversalId)
                <p><a href="{{ route('staff.ledger-reversals.show', $reversalId) }}">View posted reversal</a></p>
            @else
                <p class="print-hide"><a href="{{ route('staff.ledger-reversals.preview', ['source' => 'member-fund', 'journal' => $posting->id]) }}">Preview reversal</a></p>
            @endif
        @endif
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')

    @unless ($posting)
        <section class="panel admin-profile print-hide">
            <h2>Posting accounts</h2>
            <p>Choose the cash or bank account and the {{ $record->kind === 'share_purchase' ? 'share' : 'savings' }} account approved by finance. Use the name and legacy ID to distinguish shared account codes.</p>
            <form method="POST" action="{{ route('staff.member-fund-journals.preview', $record->id) }}" class="fields">
                @csrf
                @foreach (['cash_account' => ['Cash or bank', 'cash'], 'member_account' => [$record->kind === 'share_purchase' ? 'Share account' : 'Savings account', 'fund']] as $field => [$label, $key])
                    @php($selected = old($field, $preview[$key] ?? ''))
                    <label for="{{ $field }}">{{ $label }}</label>
                    <select id="{{ $field }}" name="{{ $field }}" required>
                        <option value="">Select account</option>
                        @foreach ($accounts->where('is_ledger_account', true)->whereNull('retired_at') as $account)
                            <option value="{{ $account->id }}" @selected(is_scalar($selected) && (string) $selected === (string) $account->id)>{{ $account->code }} · {{ $account->name }} · {{ $account->legacy_id ? 'Legacy ID '.$account->legacy_id : 'Account ID '.$account->id }}</option>
                        @endforeach
                    </select>
                @endforeach
                <button class="primary">Preview journal</button>
            </form>
        </section>
    @endunless

    @php($journal = $posting ?? ($preview ? (object) $preview['journal'] : null))
    @if ($journal)
        @php($debit = $accounts->get($journal->debit_account_id))
        @php($credit = $accounts->get($journal->credit_account_id))
        <section class="panel stacked-panel print-register">
            <h2>{{ $posting ? 'Journal lines' : 'Preview journal lines' }}</h2>
            <div class="table-wrap"><table data-table-pages>
                <thead><tr><th scope="col">Account</th><th scope="col" class="money">Debit (TZS)</th><th scope="col" class="money">Credit (TZS)</th></tr></thead>
                <tbody>
                    <tr><th scope="row">{{ $debit->code }} · {{ $debit->name }} · {{ $debit->legacy_id ? 'Legacy ID '.$debit->legacy_id : 'Account ID '.$debit->id }}</th><td class="money">{{ number_format($journal->amount_minor / 100, 2) }}</td><td class="money">—</td></tr>
                    <tr><th scope="row">{{ $credit->code }} · {{ $credit->name }} · {{ $credit->legacy_id ? 'Legacy ID '.$credit->legacy_id : 'Account ID '.$credit->id }}</th><td class="money">—</td><td class="money">{{ number_format($journal->amount_minor / 100, 2) }}</td></tr>
                </tbody>
            </table></div>
            @if ($preview)
                <form class="stacked-panel print-hide" method="POST" action="{{ route('staff.member-fund-journals.post', $record->id) }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $preview['token'] }}">
                    <button class="primary">Post journal</button>
                </form>
            @endif
        </section>
    @endif
@endsection
