@extends('layout')
@section('title', 'Loan application')
@section('content')
    <div class="page-intro">
        @can('manage-members')
            <a href="{{ route('staff.loan-applications.index', $profile->id) }}">Loan applications</a>
        @else
            <a href="{{ route('staff.loan-applications.queue') }}">Loan review</a>
        @endcan
        <h1>Loan application {{ $record->id }}</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }} · {{ ucfirst($record->status) }}</p>
    </div>
    @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
    @include('form-errors')
    <section class="panel">
        <h2>Proposed repayment schedule</h2>
        <p>Submitted by {{ $record->creator }}. {{ number_format($record->annual_basis_points / 100, 2) }}% annual interest · {{ $record->interest_method === 'flat' ? 'Flat rate' : 'Reducing balance' }} · {{ $record->term_months }} months.</p>
        @if ($record->loan_pricing_revision_id)
            <p class="note">Pricing revision {{ $record->loan_pricing_revision_id }}</p>
        @endif
        <dl class="summary">
            <div><dt>Requested principal (TZS)</dt><dd>{{ number_format($record->principal_minor / 100, 2) }}</dd></div>
            <div><dt>Financed charges (TZS)</dt><dd>{{ number_format(($record->insurance_minor + $record->processing_fee_minor + $record->other_charges_minor) / 100, 2) }}</dd></div>
            <div><dt>Total scheduled repayment (TZS)</dt><dd>{{ number_format($installments->sum('payment_minor') / 100, 2) }}</dd></div>
        </dl>
        <p class="note">Insurance {{ number_format($record->insurance_minor / 100, 2) }} TZS · Processing fee {{ number_format($record->processing_fee_minor / 100, 2) }} TZS · Other charges {{ number_format($record->other_charges_minor / 100, 2) }} TZS</p>
        <div class="table-wrap stacked-panel" tabindex="0" role="region" aria-label="Proposed repayment schedule">
            <table>
                <thead><tr><th scope="col">Month</th><th scope="col">Due date</th><th scope="col" class="money">Opening balance</th><th scope="col" class="money">Principal</th><th scope="col" class="money">Interest</th><th scope="col" class="money">Payment</th><th scope="col" class="money">Closing balance</th></tr></thead>
                <tbody>
                    @foreach ($installments as $row)
                        <tr><th scope="row">{{ $row->number }}</th><td>{{ $row->due_date }}</td>
                            @foreach (['opening_minor', 'principal_minor', 'interest_minor', 'payment_minor', 'closing_minor'] as $field)
                                <td class="money">{{ number_format($row->$field / 100, 2) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    <section class="panel stacked-panel" aria-labelledby="decision-heading">
        <h2 id="decision-heading">Review decision</h2>
        @if ($decision)
            <p>{{ ucfirst($decision->decision) }} by {{ $decision->reviewer }} on {{ \Carbon\CarbonImmutable::parse($decision->reviewed_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT.</p>
            <p>{{ $decision->reason }}</p>
            @if ($decision->minutes_id)<p><a href="{{ route('staff.loan-applications.minutes', [$profile->id, $record->id]) }}">Download board minutes (PDF)</a></p>@endif
        @elseif ($mayReview)
            <form class="fields search" method="POST" enctype="multipart/form-data" action="{{ route('staff.loan-applications.review', [$profile->id, $record->id]) }}">
                @csrf
                <label for="decision">Decision</label>
                <select id="decision" name="decision" required>
                    <option value="">Choose a decision</option>
                    <option value="approved" @selected(old('decision') === 'approved')>Approve</option>
                    <option value="declined" @selected(old('decision') === 'declined')>Decline</option>
                </select>
                <label for="reason">Reason</label>
                <textarea id="reason" name="reason" maxlength="500" rows="4" required>{{ is_string(old('reason')) ? old('reason') : '' }}</textarea>
                <label for="minutes">Board meeting minutes (PDF)</label>
                <input type="file" id="minutes" name="minutes" accept="application/pdf,.pdf" required>
                <button class="primary">Record decision</button>
            </form>
        @else
            <p>Awaiting an eligible Chairperson or Secretary decision after board review.</p>
        @endif
    </section>
    @if ($cancellation || $mayCancelForExit)
        <section class="panel stacked-panel" aria-labelledby="cancel-heading">
            <h2 id="cancel-heading">Cancellation for membership exit</h2>
            @if ($cancellation)
                <p>Cancelled by {{ $cancellation->actor }} · {{ $cancellation->cancelled_at }}</p>
                <p>{{ $cancellation->reason }}</p>
            @else
                <form class="fields search" method="POST" action="{{ route('staff.loan-applications.cancel-for-exit', [$profile->id, $record->id]) }}">
                    @csrf
                    <label for="cancel-reason">Reason for cancelling the undisbursed loan</label>
                    <textarea id="cancel-reason" name="reason" maxlength="500" required></textarea>
                    <button class="primary">Cancel undisbursed loan</button>
                </form>
            @endif
        </section>
    @endif
    @if ($record->status === 'approved' || $disbursement)
        <section class="panel stacked-panel" aria-labelledby="disbursement-heading">
            <h2 id="disbursement-heading">Disbursement</h2>
            @if ($disbursement)
                <dl class="summary">
                    <div><dt>Cash transferred (TZS)</dt><dd>{{ number_format($disbursement->cash_amount_minor / 100, 2) }}</dd></div>
                    <div><dt>Transfer date</dt><dd>{{ $disbursement->transferred_on }}</dd></div>
                    <div><dt>Transfer reference</dt><dd>{{ $disbursement->transfer_reference }}</dd></div>
                </dl>
                <p>Recorded by {{ $disbursement->recorder }} on {{ \Carbon\CarbonImmutable::parse($disbursement->recorded_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT.</p>
                @if ($disbursement->note)<p>{{ $disbursement->note }}</p>@endif
            @else
                <p>Add this loan to a <a href="{{ route('staff.loan-batches.index') }}">disbursement list</a> for Chairperson or Secretary approval and bank-stamped confirmation.</p>
            @endif
        </section>
    @endif
    @if ($disbursement)
        @include('staff.loan-applications.repayments')
    @endif
@endsection
