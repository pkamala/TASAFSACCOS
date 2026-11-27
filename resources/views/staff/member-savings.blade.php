@extends('layout')
@section('title', 'Member '.$product)
@section('content')
    <div class="page-intro">
        <h1>Member {{ $product }}</h1>
        @if ($product === 'savings')
            <p>Available savings subtract approved withdrawals awaiting payment.</p>
        @endif
    </div>
    <section class="panel">
        <form method="GET" data-live-table-search action="{{ route('staff.member-savings.index') }}" class="fields search">
            @include('form-errors')
            @if ($product !== 'savings' || request()->query('product') === 'savings')
                <input type="hidden" name="product" value="{{ $product }}">
            @endif
            <label for="q">Name or membership number</label>
            <input id="q" name="q" type="search" value="{{ $search }}" maxlength="100">
            <label for="as_of">Balance as of</label>
            <input id="as_of" name="as_of" type="date" value="{{ $asOf }}">
            @include('staff.member-status-filter')
            <button class="primary" data-search-fallback>Apply filters</button>
        </form>
        <p class="note live-table-status" data-live-table-status role="status"></p>
        @fragment('table-results')
            <div data-live-table-results>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Member {{ $product }} balances">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Member</th><th scope="col">Status</th><th scope="col">Verified opening date</th>
                                <th scope="col" class="money">{{ $productLabel }} (TZS)</th>
                                @if ($product === 'savings')
                                    <th scope="col" class="money">Reserved (TZS)</th><th scope="col" class="money">Available (TZS)</th>
                                @endif
                                <th scope="col">History</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr>
                                    <th scope="row">{{ $row->registration_no }} · {{ $row->first_name }} {{ $row->last_name }}</th>
                                    <td>{{ ucfirst($row->status) }}</td>
                                    @if ($row->as_of === null)
                                        <td colspan="{{ $product === 'savings' ? 4 : 2 }}">Awaiting verified opening balances</td>
                                    @else
                                        <td>{{ $row->as_of }}</td>
                                        <td class="money">{{ number_format($row->{$product} / 100, 2) }}</td>
                                        @if ($product === 'savings')
                                            <td class="money">{{ number_format($row->reserved / 100, 2) }}</td>
                                            <td class="money">{{ number_format($row->available / 100, 2) }}</td>
                                        @endif
                                    @endif
                                    <td><a href="{{ route('staff.member-funds.index', $row->id) }}" aria-label="View funds history for {{ $row->registration_no }}">View funds history</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $product === 'savings' ? 7 : 5 }}">No members found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @include('staff.pagination', ['page' => $rows, 'searchable' => false])
            </div>
        @endfragment
    </section>
@endsection
