@extends('layout')
@section('title', 'Membership exit')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.member-exits.index') }}">Member exits</a>
        <h1>Membership exit {{ $record->id }}</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }} · {{ ucfirst($record->status) }}</p>
    </div>
    @if (in_array($record->status, ['approved', 'finalized'], true))
        <p><a href="{{ route('staff.member-exits.voucher', $record->id) }}">View exit voucher</a></p>
    @endif
    @can('manage-members')
        @if ($record->status === 'approved' && $draft)
            <p><a href="{{ route('staff.member-exits.settlement.show', $record->id) }}">Review and post exit settlement</a></p>
        @endif
    @endcan
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
    @if (in_array($record->status, ['requested', 'approved'], true))
        <section class="panel stacked-panel">
            <h2>Draft settlement as of {{ $draftDate->format('d M Y') }}</h2>
            @if ($draft)
                <dl class="summary">
                    <div><dt>Current savings (TZS)</dt><dd>{{ number_format($draft['savings'] / 100, 2) }}</dd></div>
                    <div><dt>Withdrawal processing fee · 2% (TZS)</dt><dd>{{ number_format($draft['fee'] / 100, 2) }}</dd></div>
                    <div><dt>Outstanding loan principal (TZS)</dt><dd>{{ number_format($draft['principal'] / 100, 2) }}</dd></div>
                    <div><dt>Unpaid loan interest through {{ $draftDate->format('F Y') }} (TZS)</dt><dd>{{ number_format($draft['loanInterest'] / 100, 2) }}</dd></div>
                    <div><dt>Share refund at face value (TZS)</dt><dd>{{ number_format($draft['shares'] / 100, 2) }}</dd></div>
                    <div><dt>Subtotal before savings interest and withholding (TZS)</dt><dd>{{ number_format($draft['subtotal'] / 100, 2) }}</dd></div>
                    <div><dt>Gross unposted savings interest for {{ $draftDate->year }} · {{ $draft['interest']['annualRate'] / 100 }}% annually (TZS)</dt><dd>{{ number_format($draft['interest']['gross'] / 100, 2) }}</dd></div>
                    <div><dt>Withholding · {{ $draft['interest']['withholdingRate'] / 100 }}% of gross interest (TZS)</dt><dd>{{ number_format($draft['interest']['withholding'] / 100, 2) }}</dd></div>
                    <div><dt>Net savings interest (TZS)</dt><dd>{{ number_format($draft['interest']['net'] / 100, 2) }}</dd></div>
                    <div><dt>{{ $draft['interest']['payout'] < 0 ? 'Settlement shortfall' : 'Draft payout' }} (TZS)</dt><dd>{{ number_format(abs($draft['interest']['payout']) / 100, 2) }}</dd></div>
                </dl>
                <p>Posted net interest is included in current savings. Unposted interest is calculated for completed months in {{ $draftDate->year }} covered by verified savings records.</p>
                @if ($profile->legacy_member_id !== null)
                    <p>Reconcile any remaining legacy balances and liabilities against the clearance record.</p>
                @endif
            @else
                <p>A verified opening balance is needed to calculate the draft settlement.</p>
            @endif
        </section>
    @endif
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
