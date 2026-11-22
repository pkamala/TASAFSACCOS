@extends('layout')
@section('title', 'General journals')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-ledger.index') }}">SACCOS ledger trial balance</a>
        <h1>General journals</h1>
        <a href="{{ route('staff.general-journals.create') }}">Prepare a journal</a>
    </div>
    <section class="panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Posted general journals">
            <table>
                <thead><tr><th scope="col">Journal</th><th scope="col">Date</th><th scope="col">Category</th><th scope="col">Source reference</th><th scope="col">Posted by</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <th scope="row"><a href="{{ route('staff.general-journals.show', $row->id) }}">#{{ $row->id }}</a></th>
                            <td>{{ $row->transaction_date }}</td><td>{{ ucfirst($row->category) }}</td><td>{{ $row->source_reference }}</td><td>{{ $row->actor }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No general journals have been posted.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $rows])
    </section>
@endsection
