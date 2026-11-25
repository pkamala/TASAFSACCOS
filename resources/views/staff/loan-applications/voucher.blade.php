@extends('layout')
@section('title', 'Loan disbursement voucher')
@section('content')
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-applications.show', [$profile->id, $record->id]) }}">Loan application</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>Loan disbursement voucher {{ $record->id }}</h1>
        <p>{{ $profile->registration_no }} · {{ $profile->first_name }} {{ $profile->last_name }}</p>
        <button class="primary print-hide" type="button" onclick="window.print()">Print voucher</button>
    </div>
    <section class="panel stacked-panel print-register">
        <p>Disbursed {{ $transfer->transferred_on }} · Bank reference {{ $transfer->transfer_reference }}</p>
        <p>Recorded by {{ $transfer->recorder }}</p>
        @if ($reversed)
            <p class="status">Journal reversed</p>
        @elseif ($posting)
            <p>Posted journal {{ $posting->id }}</p>
        @else
            <p>Journal awaiting posting</p>
        @endif
        <dl class="summary">
            <div><dt>Requested principal (TZS)</dt><dd>{{ number_format($record->principal_minor / 100, 2) }}</dd></div>
            <div><dt>Contingency Fund (TZS)</dt><dd>{{ number_format($record->insurance_minor / 100, 2) }}</dd></div>
            <div><dt>Processing fee (TZS)</dt><dd>{{ number_format($record->processing_fee_minor / 100, 2) }}</dd></div>
            <div><dt>Other charges (TZS)</dt><dd>{{ number_format($record->other_charges_minor / 100, 2) }}</dd></div>
            <div><dt>Financed amount (TZS)</dt><dd>{{ number_format(($record->principal_minor + $record->insurance_minor + $record->processing_fee_minor + $record->other_charges_minor) / 100, 2) }}</dd></div>
            <div><dt>Bank transfer (TZS)</dt><dd>{{ number_format($transfer->cash_amount_minor / 100, 2) }}</dd></div>
        </dl>
    </section>
@endsection
