@extends('layout')
@section('title', 'Savings and share journals')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-ledger.index') }}">SACCOS ledger trial balance</a>
        <h1>Savings and share journals</h1>
        <p>Open a confirmed transaction from a member’s savings and shares history to prepare its journal.</p>
    </div>
    <section class="panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Posted savings and share journals">
            <table>
                <thead><tr><th scope="col">Journal</th><th scope="col">Member number</th><th scope="col">Transaction</th><th scope="col">Date</th><th scope="col">Reference</th><th scope="col" class="money">Amount (TZS)</th><th scope="col">Posted by</th></tr></thead>
                <tbody>
                    @forelse ($journals as $row)
                        <tr><th scope="row"><a href="{{ route('staff.member-fund-journals.show', $row->member_fund_transaction_id) }}">{{ $row->id }}</a></th><td>{{ $row->registration_no }}</td><td>{{ ucfirst(str_replace('_', ' ', $row->kind)) }}</td><td>{{ $row->transaction_date }}</td><td>{{ $row->source_reference }}</td><td class="money">{{ number_format($row->amount_minor / 100, 2) }}</td><td>{{ $row->actor }}</td></tr>
                    @empty
                        <tr><td colspan="7">No savings or share journals have been posted.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $journals, 'memberStatusFilter' => true])
    </section>
@endsection
