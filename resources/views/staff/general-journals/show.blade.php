@extends('layout')
@section('title', 'Posted general journal')
@section('content')
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.general-journals.index') }}">General journals</a>
        @if (request()->boolean('cash_book'))
            <a class="print-hide" href="{{ route('staff.cash-book.index', ['from' => $record->transaction_date, 'to' => $record->transaction_date]) }}">View cash book for this date</a>
            <a class="print-hide" href="{{ route('staff.cash-book.transactions.create') }}">Record another past transaction</a>
        @endif
        <h1>General journal #{{ $record->id }}</h1>
        <p>{{ $record->transaction_date }} · {{ ucfirst($record->category) }} · {{ $record->source_reference }}</p>
        <p>{{ $record->description }}</p>
        <p>Posted by {{ $record->actor }} on {{ \Carbon\CarbonImmutable::parse($record->posted_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</p>
        @unless ($exitSettlement)
            <a class="print-hide" href="{{ route('staff.ledger-reversals.preview', ['general', $record->id]) }}">View or prepare reversal</a>
        @endunless
        <a class="print-hide" href="{{ route('staff.general-journals.voucher', $record->id) }}">View {{ $record->category === 'expense' ? 'expense' : 'journal' }} voucher</a>
        <button class="print-hide" type="button" onclick="window.print()">Print journal</button>
    </div>
    <section class="panel print-register">
        @include('staff.general-journals.lines')
    </section>
@endsection
