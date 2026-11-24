@extends('layout')
@section('title', 'Payment list journals')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-batches.show', $record->id) }}">Back to payment list</a>
        <h1>Payment list {{ $record->id }} journals</h1>
        @if ($record->period)<p>{{ substr($record->period, 0, 7) }}</p>@endif
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel">
        <h2>Source transactions</h2>
        <ul class="template-list">
            @if ($sources['hasLoans'])
                <li><a href="{{ route('staff.loan-journal.show', $record->id) }}">Loan repayments</a> · {{ $sources['loanPosted'] ? 'Posted' : 'Awaiting posting' }}</li>
            @endif
            @foreach ($sources['funds'] as $fund)
                <li>
                    <a href="{{ route('staff.member-fund-journals.show', $fund->id) }}">{{ ucfirst(str_replace('_', ' ', $fund->kind)) }} #{{ $fund->id }}</a>
                    · TZS {{ number_format($fund->signed_minor / 100, 2) }}
                    · {{ $sources['postedFunds']->has($fund->id) ? 'Posted' : 'Awaiting posting' }}
                </li>
            @endforeach
        </ul>
        @if (! $sources['fundsMatch'])
            <p>The confirmed savings and share transactions do not match the payment list.</p>
        @elseif ($sources['fields'] === [])
            <p>No outstanding journals.</p>
        @else
            <form method="POST" action="{{ route('staff.payroll-journals.preview', $record->id) }}" class="fields">
                @csrf
                @foreach (['cash' => 'Bank or payroll clearing'] + $sources['fields'] as $field => $label)
                    @php($selected = old($field, $preview['accounts'][$field] ?? $defaults[$field] ?? ''))
                    <label for="payroll-{{ $field }}">{{ $label }}</label>
                    <select id="payroll-{{ $field }}" name="{{ $field }}" @required($field !== 'offset')>
                        <option value="">Select account</option>
                        @foreach ($accounts->whereNull('retired_at') as $account)
                            <option value="{{ $account->id }}" @selected(is_scalar($selected) && (string) $selected === (string) $account->id)>{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                @endforeach
                <button class="primary">Preview journals</button>
            </form>
        @endif
    </section>

    @if ($preview && $sources['fundsMatch'] && $sources['fields'] !== [])
        <section class="panel stacked-panel">
            <h2>Journal preview</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Source</th><th scope="col">Account</th><th scope="col">Debit (TZS)</th><th scope="col">Credit (TZS)</th></tr></thead>
                    <tbody>
                        @foreach ($preview['entries']['loan'] as $line)
                            @php($account = $accounts->get($line['chart_account_id']))
                            <tr>
                                <td>Loan {{ $line['loan_application_id'] }} · {{ $line['source_reference'] }}</td>
                                <td>{{ $account?->code }} {{ $account?->name }}</td>
                                <td>{{ $line['signed_minor'] > 0 ? number_format($line['signed_minor'] / 100, 2) : '' }}</td>
                                <td>{{ $line['signed_minor'] < 0 ? number_format(-$line['signed_minor'] / 100, 2) : '' }}</td>
                            </tr>
                        @endforeach
                        @foreach ($preview['entries']['funds'] as $id => $journal)
                            @foreach (['debit', 'credit'] as $side)
                                @php($account = $accounts->get($journal[$side.'_account_id']))
                                <tr>
                                    <td>{{ ucfirst(str_replace('_', ' ', $sources['funds']->get($id)?->kind ?? 'member fund')) }} #{{ $id }} · {{ $journal['source_reference'] }}</td>
                                    <td>{{ $account?->code }} {{ $account?->name }}</td>
                                    <td>{{ $side === 'debit' ? number_format($journal['amount_minor'] / 100, 2) : '' }}</td>
                                    <td>{{ $side === 'credit' ? number_format($journal['amount_minor'] / 100, 2) : '' }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
            <form method="POST" action="{{ route('staff.payroll-journals.post', $record->id) }}" class="fields">
                @csrf
                <input type="hidden" name="token" value="{{ $preview['token'] }}">
                <button class="primary">Post all journals</button>
            </form>
        </section>
    @endif
@endsection
