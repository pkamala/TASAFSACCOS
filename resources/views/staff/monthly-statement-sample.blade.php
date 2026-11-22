@extends('layout')
@section('title', 'Sample monthly statement email')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.statement-review.index', ['period' => $selected]) }}">Monthly statement review</a>
        <h1>Sample monthly statement email</h1>
        <p>This preview uses sample balances and sends no email.</p>
    </div>
    <section class="panel">
        <h2>{{ $subject }}</h2>
        <iframe class="statement-sample" title="Rendered sample statement email" sandbox srcdoc="{{ $email->toHtml() }}"></iframe>
    </section>
@endsection
