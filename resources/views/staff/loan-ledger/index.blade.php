@extends('layout')
@section('title', 'SACCOS ledger trial balance')
@section('content')
    @php($money = fn ($value) => number_format($value / 100, 2))
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-journal.index') }}">Loan journal</a>
        <p class="print-only">TASAF SACCOS</p>
        <a class="print-hide" href="{{ route('staff.member-fund-journals.index') }}">Savings and share journals</a>
        <a class="print-hide" href="{{ route('staff.general-journals.index') }}">General journals</a>
        <a class="print-hide" href="{{ route('staff.ledger-reversals.index') }}">Journal reversals</a>
        <a class="print-hide" href="{{ route('staff.ledger-periods.index') }}">Ledger periods</a>
        <a class="print-hide" href="{{ route('staff.ledger-opening.index') }}">Opening journal</a>
        <a class="print-hide" href="{{ route('staff.ledger-financial-reports', ['from' => $from, 'to' => $to]) }}">Financial reports</a>
        <h1>SACCOS ledger trial balance</h1>
        <p>{{ $from }} to {{ $to }} · TZS</p>
        <p class="note">Includes the posted opening journal, loan, member fund, and general journals, balance transfers, and reversals. Opening balances carry forward earlier activity and include the cutover journal if it is dated on the period’s first day.</p>
    </div>

    @include('staff.loan-ledger.date-filter', ['action' => route('staff.loan-ledger.index')])

    <section class="panel stacked-panel print-register">
        <div class="table-wrap" tabindex="0" role="region" aria-label="SACCOS ledger trial balance">
            <table data-table-pages>
                <thead>
                    <tr><th scope="col" rowspan="2">Account</th><th scope="colgroup" colspan="2">Opening balance</th><th scope="colgroup" colspan="2">Period movement</th><th scope="colgroup" colspan="2">Closing balance</th></tr>
                    <tr><th class="money" scope="col">Debit</th><th class="money" scope="col">Credit</th><th class="money" scope="col">Debit</th><th class="money" scope="col">Credit</th><th class="money" scope="col">Debit</th><th class="money" scope="col">Credit</th></tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $account)
                        <tr>
                            <th scope="row" class="register-name"><a href="{{ route('staff.loan-ledger.account', ['account' => $account->id, 'from' => $from, 'to' => $to]) }}">{{ $account->code }} · {{ $account->name }}</a><small>{{ $account->legacy_id ? 'Legacy ID '.$account->legacy_id : 'Account ID '.$account->id }}</small></th>
                            @foreach ([max(0, $account->opening_minor), max(0, -$account->opening_minor), $account->debit_minor, $account->credit_minor, max(0, $account->closing_minor), max(0, -$account->closing_minor)] as $value)
                                <td class="money">{{ $money($value) }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="7">No posted journal activity through this period.</td></tr>
                    @endforelse
                </tbody>
                <tfoot><tr><th scope="row">Totals</th>
                    @foreach ($totals as $value)<td class="money">{{ $money($value) }}</td>@endforeach
                </tr></tfoot>
            </table>
        </div>
    </section>
@endsection
