@extends('layout')
@section('title', 'Loan top-up voucher')
@section('content')
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-applications.show', [$profile->id, $record->id]) }}">Loan application</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>Loan top-up voucher {{ $record->id }}</h1>
        <p>{{ $profile->registration_no }} · {{ $profile->first_name }} {{ $profile->last_name }}</p>
        <button class="primary print-hide" type="button" onclick="window.print()">Print voucher</button>
    </div>
    <section class="panel stacked-panel print-register">
        <p>Disbursed {{ $transfer->transferred_on }} · Bank reference {{ $transfer->transfer_reference }}</p>
        <p>Original loan {{ $record->top_up_loan_id }} · Payoff reference {{ $payoff->receipt_reference }}</p>
        <dl class="summary">
            <div><dt>New principal (TZS)</dt><dd>{{ number_format($record->principal_minor / 100, 2) }}</dd></div>
            <div><dt>Original principal cleared (TZS)</dt><dd>{{ number_format($payoff->principal / 100, 2) }}</dd></div>
            <div><dt>Interest due on original loan (TZS)</dt><dd>{{ number_format($payoff->interest / 100, 2) }}</dd></div>
            <div><dt>Net bank transfer (TZS)</dt><dd>{{ number_format($transfer->cash_amount_minor / 100, 2) }}</dd></div>
        </dl>
    </section>
@endsection
