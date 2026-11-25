@extends('layout')
@section('title', 'New loan application')
@section('content')
    <div class="page-intro">
        <a href="{{ $selfService ? route('member.account') : route('staff.loan-intake') }}">{{ $selfService ? 'My membership' : 'Loan intake' }}</a>
        <h1>New loan application</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }}</p>
    </div>
    <section class="panel admin-profile">
        @include('form-errors')
        @if ($otherActiveLoans)
            <p>Other active loans for this member: {{ $otherActiveLoans }}.</p>
        @endif
        <form class="fields search" method="GET" action="{{ $selfService ? route('member.loan-applications.create') : route('staff.loan-applications.create', $profile->id) }}">
            @include('staff.loan-applications.category-picker')
            <button type="submit">Load category pricing</button>
        </form>
        @if ($pricing)
        <p>{{ $categories->firstWhere('id', $category)->name }} · Pricing revision {{ $pricing->id }} · {{ number_format($pricing->annual_basis_points / 100, 2) }}% annual interest · {{ $pricing->interest_method === 'flat' ? 'Flat rate' : 'Reducing balance' }}.</p>
        <p>Contingency Fund {{ number_format($pricing->insurance_basis_points / 100, 2) }}% of principal · Processing fee {{ number_format($pricing->processing_fee_minor / 100, 2) }} TZS · Other charges {{ number_format($pricing->other_charges_minor / 100, 2) }} TZS.</p>
        <form class="fields" method="POST" action="{{ $selfService ? route('member.loan-applications.store') : route('staff.loan-applications.store', $profile->id) }}">
            @csrf
            <input type="hidden" name="submission_id" value="{{ $submissionId }}">
            <input type="hidden" name="pricing_revision_id" value="{{ $pricing->id }}">
            <input type="hidden" name="loan_category_id" value="{{ $category }}">
            @if ($topUpLoans->isNotEmpty())
                <label for="top-up-loan">Original loan to clear</label>
                <select id="top-up-loan" name="top_up_loan_id">
                    <option value="">New loan</option>
                    @foreach ($topUpLoans as $loan)
                        <option value="{{ $loan }}" @selected((string) old('top_up_loan_id') === (string) $loan)>Top up loan {{ $loan }}</option>
                    @endforeach
                </select>
                <p>For a top-up, enter the full new principal, including the amount that will clear the original loan.</p>
            @endif
            <label for="principal">Requested principal (TZS)</label>
            <input id="principal" name="principal" type="number" min="100" max="100000000000" step=".01" value="{{ is_scalar(old('principal')) ? old('principal') : '' }}" required>
            <label for="term_months">Term (months)</label>
            <input id="term_months" name="term_months" type="number" min="1" max="120" value="{{ is_scalar(old('term_months')) ? old('term_months') : '' }}" required>
            <label for="first_due_date">First payment date</label>
            <input id="first_due_date" name="first_due_date" type="date" value="{{ is_scalar(old('first_due_date')) ? old('first_due_date') : '' }}" required>
            @include('staff.loan-applications.sponsor-fields', ['numbers' => [], 'selfService' => $selfService])
            <button class="primary">Submit application</button>
        </form>
        @else
            <p>An administrator must set loan pricing for this category before you can submit an application.</p>
        @endif
    </section>
@endsection
