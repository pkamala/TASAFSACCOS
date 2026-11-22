@extends('layout')
@section('title', 'Member savings')
@section('content')
    <div class="page-intro">
        <h1>Member savings</h1>
        <p>Balances use verified opening savings and confirmed transactions. Available savings subtract approved withdrawals awaiting payment.</p>
    </div>
    <section class="panel">
        <form method="GET" data-live-table-search action="{{ route('staff.member-savings.index') }}" class="fields search">
            @include('form-errors')
            <label for="q">Name or membership number</label>
            <input id="q" name="q" type="search" value="{{ $search }}" maxlength="100">
            <button class="primary" data-search-fallback>Search</button>
        </form>
        <p class="note live-table-status" data-live-table-status role="status"></p>
        @fragment('table-results')
            <div data-live-table-results>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Member savings balances">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Member</th><th scope="col">Status</th><th scope="col">Verified opening date</th>
                                <th scope="col" class="money">Savings (TZS)</th><th scope="col" class="money">Reserved (TZS)</th>
                                <th scope="col" class="money">Available (TZS)</th><th scope="col">History</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr>
                                    <th scope="row">{{ $row->registration_no }} · {{ $row->first_name }} {{ $row->last_name }}</th>
                                    <td>{{ ucfirst($row->status) }}</td>
                                    @if ($row->as_of === null)
                                        <td colspan="4">Awaiting verified opening balances</td>
                                    @else
                                        <td>{{ $row->as_of }}</td>
                                        <td class="money">{{ number_format($row->savings / 100, 2) }}</td>
                                        <td class="money">{{ number_format($row->reserved / 100, 2) }}</td>
                                        <td class="money">{{ number_format($row->available / 100, 2) }}</td>
                                    @endif
                                    <td><a href="{{ route('staff.member-funds.index', $row->id) }}" aria-label="View savings history for {{ $row->registration_no }}">View savings history</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="7">No members found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @include('staff.pagination', ['page' => $rows])
            </div>
        @endfragment
    </section>
@endsection
