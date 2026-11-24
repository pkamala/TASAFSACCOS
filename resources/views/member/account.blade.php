@extends('layout')
@section('title', 'My membership')
@section('content')
    <div class="page-intro">
        <h1>My membership</h1>
        <p>{{ $member->first_name }} {{ $member->last_name }} · {{ $member->registration_no }} · {{ ucfirst($member->status) }}</p>
        @include('member.tenure', ['joiningDate' => $member->joining_date, 'exitedOn' => $exit?->status === 'finalized' ? $exit->effective_on : null])
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    @if ($sponsorships->isNotEmpty() || $sponsorships->currentPage() > 1)
        <section class="panel stacked-panel" aria-labelledby="sponsorships-heading">
            <h2 id="sponsorships-heading">Loan sponsorships</h2>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Loan sponsorships">
                <table>
                    <thead><tr><th scope="col">Application</th><th scope="col">Borrower</th><th scope="col" class="money">Financed amount (TZS)</th><th scope="col">Term</th><th scope="col">Sponsorship</th></tr></thead>
                    <tbody>
                        @foreach ($sponsorships as $sponsorship)
                            <tr>
                                <th scope="row">{{ $sponsorship->application }}</th>
                                <td>{{ $sponsorship->first_name }} {{ $sponsorship->last_name }} · {{ $sponsorship->registration_no }}</td>
                                <td class="money">{{ number_format($sponsorship->financed_minor / 100, 2) }}</td>
                                <td>{{ $sponsorship->term_months }} months</td>
                                <td>
                                    @if ($sponsorship->confirmed_at)
                                        Confirmed
                                    @elseif ($member->status === 'active')
                                        <form method="POST" action="{{ route('member.loan-sponsorships.confirm', $sponsorship->id) }}">
                                            @csrf
                                            <button class="primary">Confirm sponsorship</button>
                                        </form>
                                    @else
                                        Awaiting confirmation from an active member
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @include('staff.pagination', ['page' => $sponsorships])
        </section>
    @endif
    <section class="panel">
        <h2>My loans</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="My loans">
            <table>
                <thead><tr><th scope="col">Reference</th><th scope="col">Category</th><th scope="col">Status</th><th scope="col" class="money">Principal (TZS)</th><th scope="col">Term</th></tr></thead>
                <tbody>
                    @forelse ($loans as $loan)
                        <tr><th scope="row">{{ $loan->id }}</th><td>{{ $loan->category_name ?? 'Uncategorized' }}</td><td>{{ ucfirst($loan->status) }}</td><td class="money">{{ number_format($loan->principal_minor / 100, 2) }} @if ($loan->interest_method === 'scheduled')<small>At cutover</small>@endif</td><td>{{ $loan->term_months }} {{ $loan->interest_method === 'scheduled' ? 'installments at cutover' : 'months' }}</td></tr>
                    @empty
                        <tr><td colspan="5">No loans on your account.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $loans])
    </section>
    <section class="panel stacked-panel">
        <h2>Savings and shares</h2>
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
