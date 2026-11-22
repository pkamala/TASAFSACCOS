@extends('layout')
@section('title', 'Posted account balance transfer')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.chart-accounts.transfer', $original->id) }}">Back to balance transfers</a>
        <h1>Account balance transfer #{{ $record->id }}</h1>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    <section class="panel">
        <p>Effective {{ $record->transaction_date }}</p>
        <p>Posted by {{ $record->actor }} on {{ \Carbon\CarbonImmutable::parse($record->posted_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</p>
        <p>{{ $record->reason }}</p>
        @include('staff.chart-account-transfer-lines', ['signedMinor' => $record->signed_minor])
    </section>
@endsection
