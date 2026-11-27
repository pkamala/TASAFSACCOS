@extends('layout')
@section('title', 'Cashbook review')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.cutover-checklist') }}">Cutover checklist</a>
        <h1>Cashbook review</h1>
        <p>Review imported transactions against existing journals before approving new entries.</p>
    </div>
    @include('form-errors')
    @if (session('status'))
        <p role="status">{{ session('status') }}</p>
    @endif
    <section class="panel stacked-panel">
        <form method="GET" class="fields" action="{{ route('staff.cash-book-review.index') }}">
            <label for="month">Workbook month</label>
            <input type="month" id="month" name="month" value="{{ $month }}">
            <label for="review-status">Status</label>
            <select id="review-status" name="status">
                <option value="">All statuses</option>
                @foreach (['pending', 'matched', 'approved', 'posted'] as $state)
                    <option value="{{ $state }}" @selected($status === $state)>{{ ucfirst($state) }}</option>
                @endforeach
            </select>
            <label for="cashbook-search">Search workbook entries</label>
            <input type="search" id="cashbook-search" name="q" value="{{ request()->string('q') }}">
            <button class="primary">Show entries</button>
        </form>
        @if ($approved > 0 && $month !== '')
            <form method="POST" action="{{ route('staff.cash-book-review.post') }}">
                @csrf
                <input type="hidden" name="month" value="{{ $month }}">
                <button class="primary">Post {{ $approved }} approved entries for {{ $month }}</button>
            </form>
        @elseif ($approved > 0)
            <p>Select a workbook month to post its approved entries.</p>
        @endif
    </section>
    <section class="panel stacked-panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Imported cashbook entries">
            <table>
                <thead><tr><th scope="col">Workbook row</th><th scope="col">Date</th><th scope="col">Payee and description</th><th scope="col">Workflow</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <th scope="row"><a href="{{ route('staff.cash-book-review.show', $entry->id) }}">{{ $entry->source_key }}</a></th>
                            <td>{{ $entry->transaction_date ?? 'Needs review' }}</td>
                            <td>{{ $entry->payee }} @if ($entry->description) · {{ $entry->description }} @endif</td>
                            <td>{{ $entry->workflow }}</td>
                            <td>{{ ucfirst($entry->status) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No cashbook entries match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $entries])
    </section>
@endsection
