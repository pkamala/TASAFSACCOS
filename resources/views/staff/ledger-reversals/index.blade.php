@extends('layout')
@section('title', 'Journal reversals')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-ledger.index') }}">SACCOS ledger trial balance</a>
        <h1>Journal reversals</h1>
        <p>Open a posted loan, member fund, or general journal to preview its reversal.</p>
    </div>
    <section class="panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Posted journal reversals">
            <table>
                <thead><tr><th scope="col">Reversal</th><th scope="col">Original journal</th><th scope="col">Ledger date</th><th scope="col">Reason</th><th scope="col">Posted by</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr><th scope="row"><a href="{{ route('staff.ledger-reversals.show', $row->id) }}">{{ $row->id }}</a></th><td>{{ match (true) { $row->loan_journal_posting_id !== null => 'Loan '.$row->loan_journal_posting_id, $row->general_journal_id !== null => 'General '.$row->general_journal_id, default => 'Member funds '.$row->member_fund_journal_id } }}</td><td>{{ $row->transaction_date }}</td><td class="register-name">{{ $row->reason }}</td><td>{{ $row->actor }}</td></tr>
                    @empty
                        <tr><td colspan="5">No journal reversals have been posted.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $rows])
    </section>
@endsection
