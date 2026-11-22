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
            <p>Upload a repayment file, a savings and shares file, or both. The files form one list for approval. Enter amounts in TZS.</p>
            <p><a href="{{ route('staff.loan-batches.repayment-template') }}">Download monthly repayment CSV template</a></p>
            <p><a href="{{ route('staff.loan-batches.contribution-template') }}">Download monthly savings and shares CSV template</a></p>
            <form method="POST" action="{{ route('staff.loan-batches.store') }}" class="fields" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="kind" value="repayment">
                <label for="period">Payroll month</label>
                <input id="period" name="period" type="month" required>
                <label for="allocations">Loan installment allocations (CSV)</label>
                <input type="file" id="allocations" name="allocations" accept=".csv,text/csv">
                <label for="contributions">Savings and share amounts (CSV)</label>
                <input type="file" id="contributions" name="contributions" accept=".csv,text/csv">
                <button class="primary">Submit payroll list for review</button>
            </form>
        </section>
    </div>
@endsection
