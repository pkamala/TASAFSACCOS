@extends('layout')
@section('title', 'Prepare payment list')
@section('content')
    <div class="page-intro"><a href="{{ route('staff.loan-batches.index') }}">Payment lists</a><h1>Prepare a payment list</h1></div>
    @include('form-errors')
    <div class="profile-grid">
        <section class="panel">
            <h2>Disbursement list</h2>
            <form method="POST" action="{{ route('staff.loan-batches.store') }}" class="fields">
                @csrf
                <input type="hidden" name="kind" value="disbursement">
                @forelse ($loans as $loan)
                    <label><input class="loan-check" type="checkbox" name="loan_ids[]" value="{{ $loan->id }}"> Loan {{ $loan->id }} · {{ $loan->registration_no }} · {{ $loan->first_name }} {{ $loan->last_name }} · TZS {{ number_format($loan->principal_minor / 100, 2) }}</label>
                @empty
                    <p>No approved loans available.</p>
                @endforelse
                @if ($loans->isNotEmpty())<button class="primary">Submit disbursement list for review</button>@endif
            </form>
        </section>
        <section class="panel">
            <h2>Monthly TASAF payroll list</h2>
            <p>Generate deductions from outstanding installments due in this month and recurring savings and shares configured on member profiles.</p>
            <p>Review the generated list before it is sent to TASAF Accounts. Include overdue installments only when their collection has been agreed.</p>
            <form method="POST" action="{{ route('staff.loan-batches.store') }}" class="fields">
                @csrf
                <input type="hidden" name="kind" value="repayment">
                <label for="period">Payroll month</label>
                <input id="period" name="period" type="month" value="{{ is_string(old('period')) ? old('period') : now('Africa/Dar_es_Salaam')->format('Y-m') }}" required>
                <label><input type="checkbox" name="include_overdue" value="1" @checked(old('include_overdue'))> Include unpaid installments from earlier months</label>
                <button class="primary">Generate payroll list</button>
            </form>
        </section>
    </div>
@endsection
