@extends('layout')
@section('title', 'Loan review')
@section('content')
    <div class="page-intro"><h1>Loan review</h1><p>Applications awaiting a Chairperson or Secretary decision.</p>
        <nav class="pagination" aria-label="Loan tools">
            <a href="{{ route('staff.loan-register', ['status' => 'submitted']) }}">Print board review list</a>
            <a href="{{ route('staff.loan-register') }}">Loan register</a>
            @can('manage-members')<a href="{{ route('staff.loan-pricing.edit') }}">Loan pricing</a>@endcan
        </nav></div>
    <section class="panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Applications awaiting review">
            <table>
                <thead><tr><th scope="col">Application</th><th scope="col">Member</th><th scope="col">Submitted by</th><th scope="col" class="money">Requested principal (TZS)</th></tr></thead>
                <tbody>
                    @forelse ($applications as $application)
                        <tr>
                            <th scope="row"><a href="{{ route('staff.loan-applications.show', [$application->member_id, $application->id]) }}">{{ $application->id }}</a></th>
                            <td>{{ $application->registration_no }} · {{ $application->first_name }} {{ $application->last_name }}</td>
                            <td>{{ $application->creator }}</td>
                            <td class="money">{{ number_format($application->principal_minor / 100, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No applications awaiting review.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $applications])
    </section>
@endsection
