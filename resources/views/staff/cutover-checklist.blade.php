@extends('layout')
@section('title', 'Cutover checklist')
@section('content')
    @php($money = fn ($minor) => number_format($minor / 100, 2))
    <div class="page-intro">
        <a href="{{ route('staff.data-imports.index') }}">Data imports</a>
        <h1>Cutover checklist</h1>
        @if ($journal)<p>Go-live {{ $journal->effective_on }} · Closing balances as of {{ $asOf }} · TZS</p>@endif
    </div>
    @include('form-errors')
    <section class="panel stacked-panel">
        <h2>Opening journal</h2>
        @if ($journal)
            <p>Posted for {{ $journal->effective_on }} · {{ $journal->review_reference }}</p>
        @else
            <p>Post the approved opening trial balance to set the cutover date and compare opening figures.</p>
        @endif
        <p><a href="{{ route('staff.ledger-opening.index') }}">Open ledger opening journal</a></p>
    </section>

    @if ($journal)
        <section class="panel stacked-panel">
            <h2>Member savings and shares</h2>
            <p>{{ number_format($members->verified_active_members) }} of {{ number_format($members->active_members) }} active members have a verified balance dated {{ $asOf }}.</p>
            <dl class="summary">
                <div><dt>Verified member balances</dt><dd>{{ number_format($members->verified_members) }}</dd></div>
                <div><dt>Verified savings (TZS)</dt><dd>{{ $money($members->savings_minor) }}</dd></div>
                <div><dt>Verified shares (TZS)</dt><dd>{{ $money($members->shares_minor) }}</dd></div>
            </dl>
            <p><a href="{{ route('staff.verified-balance-import.index') }}">Review or import verified member balances</a></p>
        </section>

        <section class="panel stacked-panel">
            <h2>Opening loan receivable</h2>
            <p>Select the ledger accounts that carry principal for the legacy loans in this review. Use account names as well as codes.</p>
            <form method="GET" action="{{ route('staff.cutover-checklist') }}" class="fields">
                <label for="receivables">Opening journal loan receivable accounts</label>
                <select id="receivables" name="receivables[]" size="6" multiple>
                    @foreach ($opening as $account)
                        <option value="{{ $account->id }}" @selected(in_array($account->id, $selected, true))>{{ $account->code }} · {{ $account->name }} · {{ $money($account->signed_minor) }}</option>
                    @endforeach
                </select>
                <button class="primary">Compare balances</button>
            </form>
            <dl class="summary">
                <div><dt>Selected opening receivable (TZS)</dt><dd>{{ $receivableMinor === null ? '—' : $money($receivableMinor) }}</dd></div>
                <div><dt>Verified legacy principal (TZS)</dt><dd>{{ $money($totals->verified_minor) }}</dd></div>
                <div><dt>Migrated principal at cutover (TZS)</dt><dd>{{ $money($totals->migrated_minor) }}</dd></div>
                @if ($receivableMinor !== null)
                    <div><dt>Opening less verified (TZS)</dt><dd>{{ $money($receivableMinor - $totals->verified_minor) }}</dd></div>
                    <div><dt>Opening less migrated (TZS)</dt><dd>{{ $money($receivableMinor - $totals->migrated_minor) }}</dd></div>
                @endif
            </dl>
            <p>{{ number_format($totals->staged_loans) }} staged legacy loans linked to members · {{ number_format($totals->unverified_loans) }} awaiting verification · {{ number_format($totals->pending_loans) }} awaiting migration · {{ number_format($totals->migrated_loans) }} migrated</p>
            @if ($totals->staged_loans === 0)
                <p>No applicable staged loans are linked to members for this cutover. Check the legacy loan and member imports.</p>
            @endif
            <p><a href="{{ route('staff.verified-legacy-loans.index') }}">Verify legacy loan balances</a> · <a href="{{ route('staff.active-loans.index') }}">Migrate active loans</a></p>
        </section>

        <section class="panel stacked-panel">
            <h2>Loans awaiting verification or migration</h2>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Legacy loans to review at cutover">
                <table>
                    <thead><tr><th scope="col">Legacy loan</th><th scope="col">Member</th><th scope="col" class="money">Verified principal (TZS)</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @forelse ($exceptions as $row)
                            <tr>
                                <th scope="row"><a href="{{ route('staff.legacy-loans.show', [$row->member_id, $row->source_id]) }}">{{ $row->reference ?: $row->source_id }}</a></th>
                                <td>{{ $row->registration_no }}</td>
                                <td class="money">{{ $row->principal_minor === null ? '—' : $money($row->principal_minor) }}</td>
                                <td>{{ $row->principal_minor === null ? 'Awaiting verification' : 'Awaiting migration' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4">No linked legacy loans awaiting verification or migration.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @include('staff.pagination', ['page' => $exceptions, 'memberStatusFilter' => true])
        </section>
    @endif
@endsection
