@extends('layout')
@section('title', 'Chart of accounts')
@section('content')
    <div class="page-intro"><h1>Chart of accounts</h1><a href="{{ route('staff.chart-accounts.create') }}">New account</a></div>
    <section class="panel">
        <form method="GET" action="{{ route('staff.accounts.index') }}" class="fields search">
            @include('form-errors')
            <label for="q">Account name or code</label>
            <input id="q" name="q" value="{{ $search }}" maxlength="100">
            <label for="category">Category</label>
            <select id="category" name="category">
                <option value="">All categories</option>
                @foreach ($categories as $option)
                    <option value="{{ $option->id }}" @selected((string) $category === (string) $option->id)>{{ $option->name }}</option>
                @endforeach
            </select>
            <label for="status">Status</label>
            <select id="status" name="status">
                @foreach (['active' => 'Active', 'retired' => 'Retired', 'all' => 'All accounts'] as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="primary">Filter accounts</button>
        </form>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Chart of accounts">
            <table>
                <thead><tr><th scope="col">Code</th><th scope="col">Account</th><th scope="col">Category</th><th scope="col">Type</th><th scope="col">Legacy ID</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    @forelse ($accounts as $account)
                        <tr>
                            <td>{{ $account->code }} @if ($account->duplicate_code)<small>Shared code</small>@endif</td>
                            <th scope="row"><a href="{{ route('staff.chart-accounts.show', $account->id) }}">{{ $account->name }}</a></th>
                            <td>{{ $account->category }}</td>
                            <td>{{ $account->type }}</td>
                            <td>{{ $account->legacy_id ?? '—' }}</td><td>{{ $account->retired_at ? 'Retired' : 'Active' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No accounts found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $accounts])
    </section>
@endsection
