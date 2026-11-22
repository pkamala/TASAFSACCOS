@extends('layout')
@section('title', 'Payment lists')
@section('content')
    <div class="page-intro"><h1>Payment lists</h1><p>Disbursement checklists and monthly repayments for TASAF Accounts.</p>
        @can('manage-members')
            <nav class="pagination" aria-label="Payment tools">
                <a href="{{ route('staff.loan-batches.create') }}">Prepare a list</a>
                <a href="{{ route('staff.loan-transactions') }}">Loan transactions report</a>
                <a href="{{ route('staff.loan-journal.index') }}">Loan journal</a>
                <a href="{{ route('staff.statement-drafts.index') }}">Monthly statement drafts</a>
            </nav>
        @endcan
    </div>
    <section class="panel">
        <div class="table-wrap"><table>
            <thead><tr><th scope="col">List</th><th scope="col">Workflow</th><th scope="col">Month</th><th scope="col">Status</th></tr></thead>
            <tbody>
                @forelse ($batches as $batch)
                    <tr><th scope="row"><a href="{{ route('staff.loan-batches.show', $batch->id) }}">{{ $batch->id }}</a></th><td>{{ ucfirst($batch->kind) }}</td><td>{{ $batch->period ? substr($batch->period, 0, 7) : '—' }}</td><td>{{ ucfirst($batch->status) }}</td></tr>
                @empty
                    <tr><td colspan="4">No payment lists prepared.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        @include('staff.pagination', ['page' => $batches])
    </section>
@endsection
