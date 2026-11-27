@extends('layout')
@section('title', 'Savings withdrawals')
@section('content')
    <div class="page-intro"><h1>Savings withdrawals</h1></div>
    <section class="panel">
        <div class="table-wrap"><table>
            <thead><tr><th scope="col">Request</th><th scope="col">Member</th><th scope="col" class="money">Amount (TZS)</th><th scope="col">Status</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr><th scope="row"><a href="{{ route('staff.savings-withdrawals.show', $row->id) }}">{{ $row->id }}</a></th><td>{{ $row->registration_no }} · {{ $row->first_name }} {{ $row->last_name }}</td><td class="money">{{ number_format($row->amount_minor / 100, 2) }}</td><td>{{ ucfirst($row->status) }}</td></tr>
            @empty
                <tr><td colspan="4">No withdrawal requests.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('staff.pagination', ['page' => $rows, 'memberStatusFilter' => true])
    </section>
@endsection
