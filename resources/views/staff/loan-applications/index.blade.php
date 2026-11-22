@extends('layout')
@section('title', 'Loan applications')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.members.show', $profile->id) }}">Member profile</a>
        <h1>Loan applications</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }}</p>
    </div>
    <section class="panel">
        @if ($profile->status === 'active')
            <p><a href="{{ route('staff.loan-applications.create', $profile->id) }}">New loan application</a></p>
        @endif
        <div class="table-wrap" tabindex="0" role="region" aria-label="Loan applications">
            <table>
                <thead><tr><th scope="col">Application</th><th scope="col">Category</th><th scope="col">Status</th><th scope="col" class="money">Requested principal (TZS)</th><th scope="col">First due date</th></tr></thead>
                <tbody>
                    @forelse ($applications as $application)
                        <tr><th scope="row"><a href="{{ route('staff.loan-applications.show', [$profile->id, $application->id]) }}">{{ $application->id }}</a></th><td>{{ $application->category_name ?? 'Uncategorized' }}</td><td>{{ ucfirst($application->status) }}</td><td class="money">{{ number_format($application->principal_minor / 100, 2) }}</td><td>{{ $application->first_due_date }}</td></tr>
                    @empty
                        <tr><td colspan="5">No new loan applications for this member.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $applications])
    </section>
@endsection
