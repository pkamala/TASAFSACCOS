@extends('layout')
@section('title', 'Membership exit')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.member-exits.index') }}">Member exits</a>
        <h1>Membership exit {{ $record->id }}</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }} · {{ ucfirst($record->status) }}</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel">
        <h2>Request and review</h2>
        <p>Requested by {{ $actors->get($record->requested_by) }} · {{ $record->requested_at }}</p>
        <p>{{ $record->reason }}</p>
        @if ($record->reviewed_by)<p>Reviewed by {{ $actors->get($record->reviewed_by) }} · {{ $record->reviewed_at }} · {{ $record->review_note }}</p>@endif
        @if ($record->finalized_by)
            <p>Finalized by {{ $actors->get($record->finalized_by) }} · {{ $record->finalized_at }} · Effective {{ $record->effective_on }}</p>
            <p>Settlement reference {{ $record->settlement_reference }}</p>
            @if ($record->legacy_clearance_reference)<p>Legacy clearance {{ $record->legacy_clearance_reference }}</p>@endif
        @endif
        @if ($record->status === 'requested')
            @can('review-loans')
                @if (auth()->user()->member_id !== $profile->id)
                    <form class="fields search" method="POST" action="{{ route('staff.member-exits.review', $record->id) }}">
                        @csrf
                        <label for="decision">Decision</label>
                        <select id="decision" name="decision" required><option value="">Choose decision</option><option value="approved">Approve</option><option value="declined">Decline</option></select>
                        <label for="review_note">Review note</label>
                        <textarea id="review_note" name="review_note" maxlength="500" required></textarea>
                        <button class="primary">Record decision</button>
                    </form>
                @endif
            @endcan
        @endif
    </section>
    @if ($record->status === 'approved')
        @can('manage-members')
            <section class="panel stacked-panel">
                <h2>Finalize after settlement</h2>
                <p>Verify outstanding liabilities and any savings or share settlement. Open loan applications and loans: {{ $openLoans }}.</p>
                <form class="fields search" method="POST" action="{{ route('staff.member-exits.finalize', $record->id) }}">
                    @csrf
                    <label for="effective_on">Exit effective date</label>
                    <input type="date" id="effective_on" name="effective_on" required>
                    <label for="settlement_reference">Savings and share settlement reference</label>
                    <textarea id="settlement_reference" name="settlement_reference" maxlength="500" required></textarea>
                    @if ($profile->legacy_member_id !== null)
                        <label for="legacy_clearance_reference">Legacy balance and liabilities clearance reference</label>
                        <textarea id="legacy_clearance_reference" name="legacy_clearance_reference" maxlength="500" required></textarea>
                    @endif
                    <button class="primary">Finalize exit and deactivate membership</button>
                </form>
            </section>
        @endcan
    @endif
@endsection
