@extends('layout')
@section('title', 'Savings withdrawal')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.savings-withdrawals.index') }}">Savings withdrawals</a>
        <h1>Savings withdrawal {{ $record->id }}</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }} · {{ ucfirst($record->status) }}</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel">
        <h2>TZS {{ number_format($record->amount_minor / 100, 2) }}</h2>
        <p>Requested by {{ $actors->get($record->requested_by) }} · {{ $record->requested_at }} · {{ $record->reason }}</p>
        @if ($record->reviewed_by)<p>Reviewed by {{ $actors->get($record->reviewed_by) }} · {{ $record->reviewed_at }} · {{ $record->review_note }}</p>@endif
        @if ($record->board_decision_reference)<p>Board decision reference {{ $record->board_decision_reference }}</p>@endif
        @if ($record->status === 'requested' && $exceptions)
            <p>Approval requires a board decision for this request.</p>
            <ul>
                @foreach ($exceptions as $exception)<li>{{ $exception }}</li>@endforeach
            </ul>
        @endif
        @if ($payment)<p>Payout {{ $payment->reference }} · {{ $payment->effective_on }} · Recorded by {{ $actors->get($payment->recorded_by) }}</p>@endif
        @if ($balances['opening'])@include('member.fund-balances')@endif
    </section>
    @if ($record->status === 'requested' && auth()->user()->member_id !== $record->member_id)
        @can('review-loans')
            <section class="panel stacked-panel admin-profile">
                <h2>Review request</h2>
                <form method="POST" action="{{ route('staff.savings-withdrawals.review', $record->id) }}" class="fields">
                    @csrf
                    <label for="decision">Decision</label>
                    <select id="decision" name="decision" required><option value="">Choose decision</option><option value="approved">Approve</option><option value="declined">Decline</option></select>
                    <label for="review_note">Review note</label>
                    <textarea id="review_note" name="review_note" maxlength="500" required></textarea>
                    @if ($exceptions)
                        <label for="board_decision_reference">Board decision reference (required to approve)</label>
                        <input id="board_decision_reference" name="board_decision_reference" maxlength="100" value="{{ old('board_decision_reference') }}">
                    @endif
                    <button class="primary">Record decision</button>
                </form>
            </section>
        @endcan
    @endif
    @if ($record->status === 'approved')
        @can('manage-members')
            <section class="panel stacked-panel admin-profile">
                <h2>Record confirmed payout</h2>
                <form method="POST" action="{{ route('staff.savings-withdrawals.pay', $record->id) }}" class="fields">
                    @csrf
                    @include('staff.savings-withdrawals.receipt-fields', ['referenceLabel' => 'Confirmed payout reference'])
                    <button class="primary">Confirm payout</button>
                </form>
            </section>
        @endcan
    @endif
@endsection
