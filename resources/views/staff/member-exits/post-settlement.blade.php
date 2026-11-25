@extends('layout')
@section('title', 'Post membership exit settlement')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.member-exits.show', $record->id) }}">Membership exit</a>
        <h1>Post exit settlement {{ $record->id }}</h1>
        <p>{{ $member->registration_no }} · {{ $member->first_name }} {{ $member->last_name }} · {{ $plan['calculated_on'] }}</p>
    </div>
    @include('form-errors')
    <section class="panel stacked-panel">
        <dl class="summary">
            <div><dt>Payout payable (TZS)</dt><dd>{{ number_format($plan['payable'] / 100, 2) }}</dd></div>
            <div><dt>Outstanding loan balance after offset (TZS)</dt><dd>{{ number_format($plan['outstanding'] / 100, 2) }}</dd></div>
        </dl>
        @if ($plan['loans'])
            @include('staff.member-exits.loan-offsets', ['loans' => $plan['loans'], 'memberId' => $member->id])
        @endif
        <form class="fields" action="{{ route('staff.member-exits.settlement.post', $record->id) }}" method="POST">
            @csrf
            <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">
            @foreach ($plan['movements'] as $kind => $amount)
                <label for="exit-account-{{ $kind }}">{{ $labels[$kind] }} · {{ $amount > 0 ? 'Debit' : 'Credit' }} TZS {{ number_format(abs($amount) / 100, 2) }}</label>
                <select id="exit-account-{{ $kind }}" name="accounts[{{ $kind }}]" required>
                    <option value="">Choose account</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected((string) old('accounts.'.$kind) === (string) $account->id)>{{ $account->code }} · {{ $account->name }}</option>
                    @endforeach
                </select>
            @endforeach
            @if ($member->legacy_member_id !== null)
                <label for="legacy-clearance">Legacy clearance reference</label>
                <input id="legacy-clearance" name="legacy_clearance_reference" maxlength="500" value="{{ old('legacy_clearance_reference') }}" required>
            @endif
            <button class="primary">Post settlement and finalize exit</button>
        </form>
    </section>
@endsection
