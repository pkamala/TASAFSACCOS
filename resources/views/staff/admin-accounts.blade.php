@extends('layout')
@section('title', 'Admin accounts')
@section('content')
    <div class="page-intro"><h1>Admin accounts</h1></div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel admin-profile">
        <h2>Create administrator</h2>
        <form method="POST" action="{{ route('staff.admin-accounts.store') }}" class="fields">
            @csrf
            <label for="admin-name">Name</label>
            <input id="admin-name" name="name" maxlength="255" value="{{ is_string(old('name')) ? old('name') : '' }}" required>
            <label for="admin-email">Email</label>
            <input id="admin-email" name="email" type="email" maxlength="255" value="{{ is_string(old('email')) ? old('email') : '' }}" required>
            <label for="admin-password">Password</label>
            <input id="admin-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
            <label for="admin-password-confirmation">Confirm password</label>
            <input id="admin-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
            <button class="primary">Create admin account</button>
        </form>
    </section>
    <section class="panel stacked-panel">
        <h2>Administrators</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">Name</th><th scope="col">Email</th></tr></thead>
                <tbody>
                    @foreach ($admins as $admin)
                        <tr><th scope="row">{{ $admin->name }}</th><td>{{ $admin->email }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $admins])
    </section>
@endsection
