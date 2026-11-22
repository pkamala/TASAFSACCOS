@extends('layout')
@section('title', 'Set account password')
@section('content')
    <section class="panel sign-in">
        <h1>Set account password</h1>
        <p>{{ $email }}</p>
        @include('form-errors')
        <form method="POST" action="{{ route('staff.password-setup.store') }}" class="fields">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">
            <input type="hidden" name="token" value="{{ $token }}">
            <label for="password">New password</label>
            <input type="password" id="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required>
            <label for="password_confirmation">Confirm new password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="72" required>
            <button class="primary">Set password</button>
        </form>
        <p><a href="{{ route('login') }}">Back to sign-in</a></p>
    </section>
@endsection
