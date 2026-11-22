@extends('layout')
@section('title', 'Sign in')
@section('content')
    <section class="panel sign-in">
        <h1>Sign in</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @include('form-errors')
        <form method="POST" action="{{ route('staff.login') }}" class="fields">
            @csrf
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ is_string(old('email')) ? old('email') : '' }}" autocomplete="username" required>
            <label for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
            <button class="primary">Sign in</button>
        </form>
        <p class="note">Contact the SACCOS administrator for account setup or a password reset.</p>
    </section>
@endsection
