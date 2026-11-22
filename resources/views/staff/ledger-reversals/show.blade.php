@extends('layout')
@section('title', 'Journal reversal')
@section('content')
    <div class="page-intro">
        <a class="print-hide" href="{{ $originalUrl }}">Original journal</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>{{ $reversal ? 'Posted journal reversal '.$reversal->id : 'Preview journal reversal' }}</h1>
        <p>{{ match ($source) { 'loan' => 'Loan journal', 'general' => 'General journal', default => 'Member fund journal' } }} {{ $journal }}</p>
        @if ($reversal)
            <p>Ledger date {{ $reversal->transaction_date }} · Posted by {{ $reversal->actor }} on {{ \Carbon\CarbonImmutable::parse($reversal->reversed_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT.</p>
            <p>{{ $reversal->reason }}</p>
            <button class="primary print-hide" type="button" onclick="window.print()">Print reversal</button>
        @endif
        <p class="note">The reversal changes ledger balances. Confirmed transfers, receipts, and member balances remain as recorded.</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel print-register">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Journal reversal lines">
            <table data-table-pages>
                <thead><tr><th scope="col">Source reference</th><th scope="col">Account</th><th scope="col" class="money">Debit (TZS)</th><th scope="col" class="money">Credit (TZS)</th></tr></thead>
                <tbody>
                    @foreach ($lines as $line)
                        @php($account = $accounts->get($line->chart_account_id))
                        <tr>
                            <th scope="row">{{ $line->source_reference }}</th><td>{{ $account->code }} · {{ $account->name }} · {{ $account->legacy_id ? 'Legacy ID '.$account->legacy_id : 'Account ID '.$account->id }}</td>
                            <td class="money">{{ $line->signed_minor > 0 ? number_format($line->signed_minor / 100, 2) : '—' }}</td>
                            <td class="money">{{ $line->signed_minor < 0 ? number_format(-$line->signed_minor / 100, 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><th scope="row" colspan="2">Totals</th><td class="money">{{ number_format($lines->where('signed_minor', '>', 0)->sum('signed_minor') / 100, 2) }}</td><td class="money">{{ number_format(-$lines->where('signed_minor', '<', 0)->sum('signed_minor') / 100, 2) }}</td></tr></tfoot>
            </table>
        </div>
    </section>
    @unless ($reversal)
        <section class="panel stacked-panel admin-profile">
            <h2>Post reversal</h2>
            <form method="POST" action="{{ route('staff.ledger-reversals.store', [$source, $journal]) }}" class="fields">
                @csrf
                <label for="transaction_date">Reversal date</label>
                <input id="transaction_date" name="transaction_date" type="date" min="{{ $date }}" max="{{ $today }}" value="{{ is_string(old('transaction_date')) ? old('transaction_date') : $today }}" required>
                <label for="reason">Reason or correction reference</label>
                <textarea id="reason" name="reason" maxlength="500" required>{{ is_string(old('reason')) ? old('reason') : '' }}</textarea>
                <button class="primary">Post journal reversal</button>
            </form>
        </section>
    @endunless
@endsection
