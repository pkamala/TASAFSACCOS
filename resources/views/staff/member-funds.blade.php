@extends('layout')
@section('title', 'Member savings and shares')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.members.show', $profile->id) }}">Member profile</a>
        <a href="{{ route('staff.member-savings.index') }}">All member savings</a>
        <h1>Member savings and shares</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }}</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel">
        @if ($balances['opening'])
            @include('member.fund-balances')
            <p class="note">Opening balances verified on {{ $balances['opening']->as_of }} · {{ $balances['opening']->evidence_reference }}</p>
        @else
            <p><a href="{{ route('staff.verified-balance-import.index') }}">Import opening balances</a> to start posting contributions.</p>
        @endif
    </section>
    @if ($balances['opening'] && $profile->status === 'active')
        <section class="panel stacked-panel admin-profile">
            <h2>Record confirmed funds</h2>
            <form method="POST" action="{{ route('staff.member-funds.deposit', $profile->id) }}" class="fields">
                @csrf
                <label for="kind">Type</label>
                <select id="kind" name="kind">
                    <option value="savings_deposit" @selected(old('kind') === 'savings_deposit')>Savings deposit</option>
                    <option value="share_purchase" @selected(old('kind') === 'share_purchase')>Share purchase</option>
                </select>
                <label for="amount">Amount (TZS)</label>
                <input id="amount" type="number" name="amount" min="0.01" step="0.01" required>
                @include('staff.savings-withdrawals.receipt-fields', ['referenceLabel' => 'Confirmed receipt reference'])
                <button class="primary">Record confirmed funds</button>
            </form>
        </section>
    @endif
    <section class="panel stacked-panel">
        <h2>Transaction history</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Member transactions"><table>
            <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Reference</th><th scope="col" class="money">Amount (TZS)</th><th scope="col">Recorded by</th><th scope="col">Note</th><th scope="col">Journal</th></tr></thead>
            <tbody>
                @forelse ($history as $row)
                    <tr><td>{{ $row->effective_on }}</td><th scope="row">{{ ucfirst(str_replace('_', ' ', $row->kind)) }}</th><td>{{ $row->reference }}</td><td class="money">{{ number_format($row->signed_minor / 100, 2) }}</td><td>{{ $row->actor }} · {{ $row->recorded_at }}</td><td>{{ $row->note ?? '—' }}</td><td><a href="{{ route('staff.member-fund-journals.show', $row->id) }}">{{ $row->journal_id ? 'View posted journal' : 'Prepare journal' }}</a></td></tr>
                @empty
                    <tr><td colspan="7">No confirmed transactions recorded.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        @include('staff.pagination', ['page' => $history])
    </section>
@endsection
