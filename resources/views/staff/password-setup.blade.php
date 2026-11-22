@extends('layout')
@section('title', 'Set staff password')
@section('content')
    <section class="panel sign-in">
        <h1>{{ $local ? 'Create your member account' : 'Set staff password' }}</h1>
        @if ($local)
            <p>Enter your registered email and membership number, then choose a password.</p>
        @else
            <p>{{ $email }}</p>
        @endif
        @include('form-errors')
        <form method="POST" action="{{ route($local ? 'staff.local-setup.store' : 'staff.password-setup.store') }}" class="fields">
            @csrf
            @if ($local)
                <label for="email">Registered email</label>
                <input type="email" id="email" name="email" autocomplete="username" value="{{ is_string(old('email')) ? old('email') : '' }}" required>
                <label for="registration_no">Membership number</label>
                <input id="registration_no" name="registration_no" maxlength="30" value="{{ is_string(old('registration_no')) ? old('registration_no') : '' }}" required>
            @else
                <input type="hidden" name="email" value="{{ $email }}">
                <input type="hidden" name="token" value="{{ $token }}">
            @endif
            <label for="password">New password</label>
            <input type="password" id="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required>
            <label for="password_confirmation">Confirm new password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="72" required>
            <button class="primary">Set password</button>
        </form>
        <p><a href="{{ route('login') }}">Back to sign-in</a></p>
    </section>
@endsection
