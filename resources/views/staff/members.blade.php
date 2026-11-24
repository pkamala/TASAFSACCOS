@extends('layout')
@section('title', $intake ? 'Loan intake' : 'Members')
@section('content')
    <div class="page-intro"><h1>{{ $intake ? 'Loan intake' : 'Members' }}</h1>
        @unless ($intake)
        <a href="{{ route('staff.members.create') }}">New member</a>
        <a href="{{ route('staff.verified-balance-import.index') }}">Import verified opening balances</a>
        @endunless
    </div>
    <section class="panel">
        <form method="GET" data-live-table-search action="{{ route($intake ? 'staff.loan-intake' : 'staff.members.index') }}" class="search">
            <label for="q">Name or registration number</label>
            <div><input id="q" name="q" type="search" value="{{ $search }}" maxlength="100"><button class="primary" data-search-fallback>Search</button></div>
            @unless ($intake)
                <label for="status">Membership status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    @foreach (['active', 'inactive', 'exited'] as $state)
                        <option value="{{ $state }}" @selected($status === $state)>{{ ucfirst($state) }}</option>
                    @endforeach
                </select>
            @endunless
        </form>
        <p class="note live-table-status" data-live-table-status role="status"></p>
        @fragment('table-results')
        <div data-live-table-results>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Registration number</th><th scope="col">Name</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @forelse ($members as $member)
                            <tr><td>{{ $member->registration_no }}</td><td><a href="{{ route($intake ? 'staff.loan-applications.create' : 'staff.members.show', $member->id) }}">{{ $member->first_name }} {{ $member->last_name }}</a></td><td>{{ ucfirst($member->status) }}</td></tr>
                        @empty
                            <tr><td colspan="3">No members found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @include('staff.pagination', ['page' => $members])
        </div>
        @endfragment
    </section>
@endsection
