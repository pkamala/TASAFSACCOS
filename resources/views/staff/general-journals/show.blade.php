@extends('layout')
@section('title', 'Posted general journal')
@section('content')
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.general-journals.index') }}">General journals</a>
        <h1>General journal #{{ $record->id }}</h1>
        <p>{{ $record->transaction_date }} · {{ ucfirst($record->category) }} · {{ $record->source_reference }}</p>
        <p>{{ $record->description }}</p>
        <p>Posted by {{ $record->actor }} on {{ \Carbon\CarbonImmutable::parse($record->posted_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</p>
        <a class="print-hide" href="{{ route('staff.ledger-reversals.preview', ['general', $record->id]) }}">View or prepare reversal</a>
        <button class="print-hide" type="button" onclick="window.print()">Print journal</button>
    </div>
    <section class="panel print-register">
        @include('staff.general-journals.lines')
    </section>
@endsection
