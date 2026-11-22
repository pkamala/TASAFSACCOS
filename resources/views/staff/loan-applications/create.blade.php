@extends('layout')
@section('title', 'New loan application')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-applications.index', $profile->id) }}">Loan applications</a>
        <h1>New loan application</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }}</p>
    </div>
    <section class="panel admin-profile">
        @include('form-errors')
        @if ($eligibility['minimums'])
            <p>Minimum savings: TZS {{ number_format($eligibility['minimums']->min_savings_minor / 100, 2) }} · Minimum shares: TZS {{ number_format($eligibility['minimums']->min_shares_minor / 100, 2) }}</p>
        @endif
        @if ($eligibility['balance'])
            <p>Available savings: TZS {{ number_format($eligibility['funds']['available'] / 100, 2) }} · Share value: TZS {{ number_format($eligibility['funds']['shares'] / 100, 2) }}</p>
        @endif
        @if ($otherActiveLoans)
            <p>Other active loans for this member: {{ $otherActiveLoans }}.</p>
        @endif
        <form class="fields search" method="GET" action="{{ route('staff.loan-applications.create', $profile->id) }}">
            @include('staff.loan-applications.category-picker')
            <button type="submit">Load category pricing</button>
        </form>
        @if ($eligibility['reason'])
            <p>{{ $eligibility['reason'] }} <a href="{{ route('staff.member-balances.index', $profile->id) }}">View verified balances</a></p>
        @elseif ($pricing)
        <p>{{ $categories->firstWhere('id', $category)->name }} · Pricing revision {{ $pricing->id }} · {{ number_format($pricing->annual_basis_points / 100, 2) }}% annual interest · {{ $pricing->interest_method === 'flat' ? 'Flat rate' : 'Reducing balance' }}.</p>
        <p>Insurance {{ number_format($pricing->insurance_basis_points / 100, 2) }}% of principal · Processing fee {{ number_format($pricing->processing_fee_minor / 100, 2) }} TZS · Other charges {{ number_format($pricing->other_charges_minor / 100, 2) }} TZS.</p>
        <form class="fields" method="POST" action="{{ route('staff.loan-applications.store', $profile->id) }}">
            @csrf
            <input type="hidden" name="submission_id" value="{{ $submissionId }}">
            <input type="hidden" name="pricing_revision_id" value="{{ $pricing->id }}">
            <input type="hidden" name="loan_category_id" value="{{ $category }}">
            <label for="principal">Requested principal (TZS)</label>
            <input id="principal" name="principal" type="number" min="100" max="100000000000" step=".01" value="{{ is_scalar(old('principal')) ? old('principal') : '' }}" required>
            <label for="term_months">Term (months)</label>
            <input id="term_months" name="term_months" type="number" min="1" max="120" value="{{ is_scalar(old('term_months')) ? old('term_months') : '' }}" required>
            <label for="first_due_date">First payment date</label>
            <input id="first_due_date" name="first_due_date" type="date" value="{{ is_scalar(old('first_due_date')) ? old('first_due_date') : '' }}" required>
            <button class="primary">Submit application</button>
        </form>
        @else
            <p>Set <a href="{{ route('staff.loan-pricing.edit', compact('category')) }}">loan pricing</a> before submitting applications.</p>
        @endif
    </section>
@endsection
