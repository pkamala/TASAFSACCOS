@extends('layout')
@section('title', 'Staff profile')
@section('content')
    <div class="page-intro"><h1>Staff profile</h1></div>
    <section class="panel admin-profile">
        @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
        @include('form-errors')
        <form method="POST" action="{{ route('staff.profile.update') }}" class="fields">
            @csrf
            @method('PATCH')
            <label for="name">Name</label>
            <input id="name" name="name" value="{{ is_string(old('name', $admin->name)) ? old('name', $admin->name) : $admin->name }}" maxlength="255" autocomplete="name" required>
            <label for="email">Sign-in email</label>
            <input id="email" type="email" value="{{ $admin->email }}" autocomplete="username" readonly>
            <fieldset class="fields charges">
                <legend>Change password <small>Optional</small></legend>
                <label for="current_password">Current password</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password">
                <label for="password">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
            </fieldset>
            <button class="primary">Save profile</button>
        </form>
    </section>
@endsection
