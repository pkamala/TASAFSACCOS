@extends('layout')
@section('title', 'Loan review')
@section('content')
    <div class="page-intro"><h1>Loan review</h1><p>Submitted applications and their current review stage.</p>
        <nav class="pagination" aria-label="Loan tools">
            @can('access-loan-workflow')
            <a href="{{ route('staff.loan-register', ['status' => 'submitted']) }}">Print submitted applications</a>
            <a href="{{ route('staff.loan-register') }}">Loan register</a>
            @endcan
            @can('submit-loans')<a href="{{ route('staff.loan-intake') }}">New loan application</a>@endcan
            @can('manage-members')<a href="{{ route('staff.loan-pricing.edit') }}">Loan pricing</a>@endcan
        </nav></div>
    <section class="panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Applications awaiting review">
            <table>
                <thead><tr><th scope="col">Application</th><th scope="col">Member</th><th scope="col">Category</th><th scope="col">Submitted by</th><th scope="col">Stage</th><th scope="col" class="money">Requested principal (TZS)</th></tr></thead>
                <tbody>
                    @forelse ($applications as $application)
                        <tr>
                            <th scope="row"><a href="{{ route('staff.loan-applications.show', [$application->member_id, $application->id]) }}">{{ $application->id }}</a></th>
                            <td>{{ $application->registration_no }} · {{ $application->first_name }} {{ $application->last_name }}</td>
                            <td>{{ $application->category_name ?? 'Uncategorized' }}</td><td>{{ $application->creator }}</td>
                            <td>{{ match (true) { in_array($application->approval_lane, \App\LoanApproval::PendingLanes, true) => (int) $application->confirmed_sponsors === \App\LoanSponsorship::RequiredCount ? 'Loan officer' : 'Awaiting sponsor confirmations', $application->approval_lane === 'committee' && ! $application->board_borrower => 'Loan committee', default => 'Board officers' } }}</td>
                            <td class="money">{{ number_format($application->principal_minor / 100, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No applications awaiting review.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $applications, 'memberStatusFilter' => true])
    </section>
@endsection
