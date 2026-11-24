@extends('layout')
@section('title', 'Loan journal entry')
@section('content')
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-batches.show', $record->id) }}">Payment list {{ $record->id }}</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>{{ $posting ? 'Posted loan journal '.$posting->id : 'Prepare loan journal' }}</h1>
        <p>{{ ucfirst($record->kind) }} · Payment list {{ $record->id }} · {{ $record->effective_date }}</p>
        @if ($posting)
            <p>Posted by {{ $posting->actor }} on {{ \Carbon\CarbonImmutable::parse($posting->posted_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT.</p>
            <button class="primary print-hide" type="button" onclick="window.print()">Print journal</button>
            @if ($reversalId)
                <p><a href="{{ route('staff.ledger-reversals.show', $reversalId) }}">View posted reversal</a></p>
            @else
                <p class="print-hide"><a href="{{ route('staff.ledger-reversals.preview', ['source' => 'loan', 'journal' => $posting->id]) }}">Preview reversal</a></p>
            @endif
        @endif
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    @if ($record->kind === 'repayment')
        <p class="print-hide"><a href="{{ route('staff.payroll-journals.show', $record->id) }}">Review and post payment list journals</a></p>
    @endif
    @unless ($posting)
        <section class="panel admin-profile print-hide">
            <h2>Posting accounts</h2>
            <p>Choose the accounts approved by finance for this list. Where an account code is shared, use the account name and legacy ID to distinguish it.</p>
            <form class="fields" method="POST" action="{{ route('staff.loan-journal.preview', $record->id) }}">
                @csrf
                @foreach (['cash_account' => ['Cash or bank', 'cash'], 'principal_account' => ['Loan principal receivable', 'principal'], 'offset_account' => [$offset, 'offset']] as $field => [$label, $key])
                    @php($selected = old($field, $preview['accounts'][$key] ?? $defaults[$key] ?? ''))
                    <label for="{{ $field }}">{{ $label }} @if ($key === 'offset')<small>Required if the amount is nonzero</small>@endif</label>
                    <select id="{{ $field }}" name="{{ $field }}" @required($key !== 'offset')>
                        <option value="">Select account</option>
                        @foreach ($accounts->whereNull('retired_at') as $account)
                            <option value="{{ $account->id }}" @selected(is_scalar($selected) && (string) $selected === (string) $account->id)>{{ $account->code }} · {{ $account->name }} · {{ $account->legacy_id ? 'Legacy ID '.$account->legacy_id : 'Account ID '.$account->id }}</option>
                        @endforeach
                    </select>
                @endforeach
                <button class="primary">Preview journal</button>
            </form>
        </section>
    @endunless
    @if ($lines->isNotEmpty())
        <section class="panel stacked-panel print-register">
            <h2>{{ $posting ? 'Journal lines' : 'Preview journal lines' }}</h2>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Loan journal lines">
                <table data-table-pages>
                    <thead><tr><th scope="col">Loan</th><th scope="col">Date</th><th scope="col">Source reference</th><th scope="col">Account</th><th scope="col" class="money">Debit (TZS)</th><th scope="col" class="money">Credit (TZS)</th></tr></thead>
                    <tbody>
                        @foreach ($lines as $line)
                            @php($account = $accounts->get($line->chart_account_id))
                            <tr>
                                <th scope="row">{{ $line->loan_application_id }}</th><td>{{ $line->transaction_date }}</td><td>{{ $line->source_reference }}</td>
                                <td>{{ $account->code }} · {{ $account->name }} · {{ $account->legacy_id ? 'Legacy ID '.$account->legacy_id : 'Account ID '.$account->id }}</td>
                                <td class="money">{{ $line->signed_minor > 0 ? number_format($line->signed_minor / 100, 2) : '—' }}</td>
                                <td class="money">{{ $line->signed_minor < 0 ? number_format(-$line->signed_minor / 100, 2) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><th scope="row" colspan="4">Total</th><td class="money">{{ number_format($lines->where('signed_minor', '>', 0)->sum('signed_minor') / 100, 2) }}</td><td class="money">{{ number_format(-$lines->where('signed_minor', '<', 0)->sum('signed_minor') / 100, 2) }}</td></tr></tfoot>
                </table>
            </div>
            @if ($preview)
                <form class="stacked-panel print-hide" method="POST" action="{{ route('staff.loan-journal.post', $record->id) }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $preview['token'] }}">
                    <button class="primary">Post journal</button>
                </form>
            @endif
        </section>
    @endif
@endsection
