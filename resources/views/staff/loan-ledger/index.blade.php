@extends('layout')
@section('title', 'SACCOS ledger trial balance')
@section('content')
    @php
        $money = fn ($value) => number_format($value / 100, 2);
        $reportDate = strtoupper(\Carbon\CarbonImmutable::parse($to)->format('d-M-Y'));
    @endphp
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-journal.index') }}">Loan journal</a>
        <p class="print-only">TASAF SACCOS</p>
        <a class="print-hide" href="{{ route('staff.member-fund-journals.index') }}">Savings and share journals</a>
        <a class="print-hide" href="{{ route('staff.general-journals.index') }}">General journals</a>
        <a class="print-hide" href="{{ route('staff.ledger-reversals.index') }}">Journal reversals</a>
        <a class="print-hide" href="{{ route('staff.ledger-periods.index') }}">Ledger periods</a>
        <a class="print-hide" href="{{ route('staff.ledger-opening.index') }}">Opening journal</a>
        <a class="print-hide" href="{{ route('staff.ledger-financial-reports', ['from' => $from, 'to' => $to]) }}">Financial reports</a>
        <a class="print-hide" href="{{ route('staff.cash-book.index', compact('from', 'to')) }}">Cash book</a>
        <h1>SACCOS ledger trial balance</h1>
        <p>{{ $from }} to {{ $to }} · TZS</p>
        <p class="note">Includes the posted opening journal, loan, member fund, and general journals, balance transfers, and reversals. Opening balances carry forward earlier activity and include the cutover journal if it is dated on the period’s first day.</p>
    </div>

    @include('staff.loan-ledger.date-filter', ['action' => route('staff.loan-ledger.index')])

    <section class="panel stacked-panel print-register">
        <div class="table-wrap" tabindex="0" role="region" aria-label="SACCOS ledger trial balance">
            <table data-table-pages data-export-template="trial-balance">
                <thead>
                    <tr>
                        @foreach (['TRANS_DATE', 'GRP', 'TRANS_DATE1', 'ACCOUNT', 'DESCRIPTION', 'GL_BFWBAL', 'DEBITS', 'CREDITS', 'NET_CHANGE', 'ENDING_BAL'] as $column)
                            <th scope="col">{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $account)
                        <tr>
                            <td>{{ $reportDate }}</td>
                            <td>{{ preg_match('/^[1-5][0-9]{3}$/', $account->code) ? $account->code[0].'00' : '' }}</td>
                            <td>{{ $reportDate }}</td>
                            <th scope="row"><a href="{{ route('staff.loan-ledger.account', ['account' => $account->id, 'from' => $from, 'to' => $to]) }}">{{ $account->code }}</a></th>
                            <td class="register-name">{{ $account->name }}</td>
                            @foreach ([$account->opening_minor, $account->debit_minor, -$account->credit_minor, $account->debit_minor - $account->credit_minor, $account->closing_minor] as $value)
                                <td class="money">{{ $money($value) }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="10">No posted journal activity through this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
