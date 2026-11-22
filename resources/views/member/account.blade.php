@extends('layout')
@section('title', 'My membership')
@section('content')
    <div class="page-intro">
        <h1>My membership</h1>
        <p>{{ $member->first_name }} {{ $member->last_name }} · {{ $member->registration_no }} · {{ ucfirst($member->status) }}</p>
    </div>
    <section class="panel">
        <h2>My loan applications</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="My loan applications">
            <table>
                <thead><tr><th scope="col">Reference</th><th scope="col">Category</th><th scope="col">Status</th><th scope="col" class="money">Requested amount (TZS)</th><th scope="col">Term (months)</th></tr></thead>
                <tbody>
                    @forelse ($loans as $loan)
                        <tr><th scope="row">{{ $loan->id }}</th><td>{{ $loan->category_name ?? 'Uncategorized' }}</td><td>{{ ucfirst($loan->status) }}</td><td class="money">{{ number_format($loan->principal_minor / 100, 2) }}</td><td>{{ $loan->term_months }}</td></tr>
                    @empty
                        <tr><td colspan="5">No new loan applications on your account.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $loans])
    </section>
    <section class="panel stacked-panel">
        <h2>Savings and shares</h2>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @include('form-errors')
        @if ($balances['opening'])
            @include('member.fund-balances')
            @if ($withdrawal)<p>Latest withdrawal · {{ ucfirst($withdrawal->status) }} · TZS {{ number_format($withdrawal->amount_minor / 100, 2) }}</p>@endif
            @if ($member->status === 'active' && $balances['available'] > 0 && ! in_array($withdrawal->status ?? null, ['requested', 'approved'], true) && ! in_array($exit->status ?? null, ['requested', 'approved'], true))
                <form method="POST" class="fields search" action="{{ route('member.savings-withdrawal') }}">
                    @csrf
                    <label for="withdrawal-amount">Savings withdrawal amount (TZS)</label>
                    <input id="withdrawal-amount" name="amount" type="number" min="0.01" step="0.01" required>
                    <label for="withdrawal-reason">Reason for withdrawal</label>
                    <textarea id="withdrawal-reason" name="reason" maxlength="500" required></textarea>
                    <button class="primary">Request savings withdrawal</button>
                </form>
            @endif
        @else
            <p>Your opening savings and share balances are awaiting verification.</p>
        @endif
    </section>
    <section class="panel stacked-panel">
        <h2>Membership exit</h2>
        @if ($exit)
            <p>Latest request · {{ ucfirst($exit->status) }} @if ($exit->effective_on) · Effective {{ $exit->effective_on }} @endif</p>
        @endif
        @if ($member->status === 'active' && ! in_array($exit->status ?? null, ['requested', 'approved'], true))
            <form method="POST" class="fields search" action="{{ route('member.exit') }}">
                @csrf
                <label for="exit-reason">Reason for leaving</label>
                <textarea id="exit-reason" name="reason" maxlength="500" required></textarea>
                <button class="primary">Request membership exit</button>
            </form>
        @endif
    </section>
@endsection
