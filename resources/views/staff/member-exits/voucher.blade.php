@extends('layout')
@section('title', 'Membership exit voucher')
@section('content')
    @if (session('status'))<p class="print-hide" role="status">{{ session('status') }}</p>@endif
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.member-exits.show', $record->id) }}">Membership exit</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>{{ $record->status === 'approved' ? 'Draft membership exit voucher' : 'Membership exit voucher' }} {{ $record->id }}</h1>
        <p>{{ $profile->registration_no }} · {{ $profile->first_name }} {{ $profile->last_name }}</p>
        @if ($snapshot)
            <button class="primary print-hide" type="button" onclick="window.print()">Print voucher</button>
        @endif
    </div>
    <section class="panel stacked-panel print-register">
        @if ($record->effective_on)
            <p>Effective date {{ $record->effective_on }}</p>
            <p>Settlement reference {{ $record->settlement_reference }}</p>
            @if ($record->legacy_clearance_reference)<p>Legacy clearance reference {{ $record->legacy_clearance_reference }}</p>@endif
        @endif
        @can('manage-members')
            @if ($record->settlement_journal_id)
                <a class="print-hide" href="{{ route('staff.general-journals.show', $record->settlement_journal_id) }}">View posted settlement journal</a>
            @endif
        @endcan
        @if ($snapshot)
            @php($amounts = $snapshot['amounts'])
            <p>Balances calculated on {{ $snapshot['calculated_on'] }}</p>
            <table>
                <thead><tr><th scope="col">Settlement item</th><th scope="col" class="money">Amount (TZS)</th></tr></thead>
                <tbody>
                    @foreach ([
                        'Savings balance' => $amounts['savings'],
                        'Processing fee (2%)' => -$amounts['fee'],
                        'Outstanding loan principal' => -$amounts['principal'],
                        'Unpaid loan interest through settlement month' => -$amounts['loanInterest'],
                        'Gross unposted savings interest' => $amounts['interest']['gross'],
                        'Withholding on savings interest' => -$amounts['interest']['withholding'],
                        'Share refund' => $amounts['shares'],
                    ] as $label => $amount)
                        <tr><th scope="row">{{ $label }}</th><td class="money">{{ number_format($amount / 100, 2) }}</td></tr>
                    @endforeach
                </tbody>
                <tfoot><tr><th scope="row">{{ $amounts['interest']['payout'] < 0 ? 'Settlement shortfall' : 'Net amount due to member' }}</th><td class="money">{{ number_format(abs($amounts['interest']['payout']) / 100, 2) }}</td></tr></tfoot>
            </table>
            @if (isset($snapshot['payable']))
                <dl class="summary">
                    <div><dt>Payout payable at settlement (TZS)</dt><dd>{{ number_format($snapshot['payable'] / 100, 2) }}</dd></div>
                    <div><dt>Outstanding loan balance after offset (TZS)</dt><dd>{{ number_format($snapshot['outstanding'] / 100, 2) }}</dd></div>
                </dl>
                @if ($snapshot['loans'])
                    @include('staff.member-exits.loan-offsets', ['loans' => $snapshot['loans'], 'memberId' => $profile->id])
                @endif
            @endif
        @else
            <p>{{ $record->status === 'finalized' ? 'No calculation was saved for this exit.' : 'A verified opening balance is needed to calculate the voucher.' }}</p>
        @endif
    </section>
@endsection
