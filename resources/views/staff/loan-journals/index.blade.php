@extends('layout')
@section('title', 'Loan journal')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-batches.index') }}">Payment lists</a>
        <h1>Loan journal</h1>
        <p>Post a confirmed disbursement or repayment list from its payment list page.</p>
        <a href="{{ route('staff.loan-ledger.index') }}">SACCOS ledger trial balance</a>
    </div>
    <section class="panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Posted loan journals">
            <table>
                <thead><tr><th scope="col">Journal</th><th scope="col">Payment list</th><th scope="col">Kind</th><th scope="col">Transaction date</th><th scope="col">Posted by</th><th scope="col">Posted at</th></tr></thead>
                <tbody>
                    @forelse ($postings as $row)
                        <tr><th scope="row"><a href="{{ route('staff.loan-journal.show', $row->loan_batch_id) }}">{{ $row->id }}</a></th><td>{{ $row->loan_batch_id }}</td><td>{{ ucfirst($row->kind) }}</td><td>{{ $row->effective_date }}</td><td>{{ $row->actor }}</td><td>{{ \Carbon\CarbonImmutable::parse($row->posted_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</td></tr>
                    @empty
                        <tr><td colspan="6">No loan journals have been posted.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $postings])
    </section>
@endsection
