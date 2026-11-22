@extends('layout')
@section('title', 'SACCOS ledger account activity')
@section('content')
    @php($money = fn ($value) => number_format($value / 100, 2))
    @php($balance = fn ($value) => $money(abs($value)).($value === 0 ? '' : ($value > 0 ? ' Dr' : ' Cr')))
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-ledger.index', compact('from', 'to')) }}">SACCOS ledger trial balance</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>{{ $record->code }} · {{ $record->name }}</h1>
        <p>SACCOS ledger activity · {{ $record->legacy_id ? 'Legacy ID '.$record->legacy_id : 'Account ID '.$record->id }} · {{ $from }} to {{ $to }} · TZS</p>
    </div>

    @include('staff.loan-ledger.date-filter', ['action' => route('staff.loan-ledger.account', $record->id)])

    <section class="panel stacked-panel print-register">
        <dl class="summary">
            <div><dt>Opening balance</dt><dd>{{ $balance($openingBalance) }}</dd></div>
            <div><dt>Closing balance</dt><dd>{{ $balance($closingBalance) }}</dd></div>
        </dl>
        <div class="table-wrap stacked-panel" tabindex="0" role="region" aria-label="Account journal lines">
            <table>
                <thead><tr><th scope="col">Date</th><th scope="col">Transaction</th><th scope="col">Source reference</th><th scope="col">Journal</th><th scope="col" class="money">Debit</th><th scope="col" class="money">Credit</th><th scope="col" class="money">Running balance</th></tr></thead>
                <tbody>
                    @forelse ($entries as $row)
                        @php($journalRoute = match ($row->source_kind) { 'loan' => 'staff.loan-journal.show', 'reversal' => 'staff.ledger-reversals.show', 'opening' => 'staff.ledger-opening.index', 'account_transfer' => 'staff.chart-account-transfers.show', 'general' => 'staff.general-journals.show', default => 'staff.member-fund-journals.show' })
                        <tr><td>{{ $row->transaction_date }}</td><td>{{ match ($row->source_kind) { 'loan' => 'Loan '.$row->subject_id, 'reversal' => 'Journal reversal', 'opening' => 'Opening journal', 'account_transfer' => 'Account balance transfer', 'general' => 'General journal', default => 'Member funds '.$row->subject_id } }}</td><th scope="row">{{ $row->source_reference }}</th>
                            <td><a href="{{ route($journalRoute, $row->source_kind === 'opening' ? [] : [$row->journal_reference]) }}">View journal</a></td>
                            <td class="money">{{ $money(max(0, $row->signed_minor)) }}</td><td class="money">{{ $money(max(0, -$row->signed_minor)) }}</td>
                            <td class="money">{{ $balance($row->balance_minor) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No posted entries for this account in the period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
