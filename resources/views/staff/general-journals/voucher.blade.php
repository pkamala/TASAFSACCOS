@extends('layout')
@section('title', $record->category === 'expense' ? 'Expense voucher' : 'General journal voucher')
@section('content')
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.general-journals.show', $record->id) }}">Posted journal</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>{{ $record->category === 'expense' ? 'Expense voucher' : 'General journal voucher' }} {{ $record->id }}</h1>
        <p>{{ $record->transaction_date }} · Reference {{ $record->source_reference }}</p>
        @if ($reversed)<p class="status">Journal reversed</p>@endif
        <button class="primary print-hide" type="button" onclick="window.print()">Print voucher</button>
    </div>
    <section class="panel stacked-panel print-register">
        <p>{{ $record->description }}</p>
        <p>Posted by {{ $record->actor }} · {{ \Carbon\CarbonImmutable::parse($record->posted_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</p>
        @include('staff.general-journals.lines')
    </section>
@endsection
