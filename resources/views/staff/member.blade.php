@extends('layout')
@section('title', 'Member profile')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.members.index') }}">All members</a>
        <h1>{{ $profile->first_name }} {{ $profile->last_name }}</h1>
        <p>{{ $profile->registration_no }} · {{ ucfirst($profile->status) }}</p>
        <a href="{{ route('staff.loan-applications.index', $profile->id) }}">Loan applications</a>
        <a href="{{ route('staff.member-funds.index', $profile->id) }}">Savings and shares</a>
        <a href="{{ route('staff.member-balances.index', $profile->id) }}">Verified savings and shares</a>
        <a href="{{ route('staff.member-exits.create', $profile->id) }}">Membership exit</a>
        @if ($profile->legacy_member_id)
            <a href="{{ route('staff.legacy-loans.index', $profile->id) }}">Legacy loans and repayments</a>
            <a href="{{ route('staff.legacy-member-transactions', $profile->id) }}">Legacy savings and contributions</a>
        @endif
    </div>
    @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
    <div class="profile-grid">
        <section class="panel">
            <h2>Profile details</h2>
            <p>{{ $profile->email }}</p>
            @include('form-errors')
            <form method="POST" action="{{ route('staff.members.update', $profile->id) }}" class="fields">
                @csrf
                @method('PATCH')
                @foreach (['first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name', 'phone' => 'Phone', 'joining_date' => 'Joining date'] as $field => $label)
                    <label for="{{ $field }}">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="{{ match($field) { 'joining_date' => 'date', 'phone' => 'tel', default => 'text' } }}" value="{{ is_string(old($field, $profile->$field)) ? old($field, $profile->$field) : '' }}" @required(in_array($field, ['first_name', 'last_name'], true))>
                @endforeach
                <button class="primary">Save profile</button>
            </form>
        </section>
        <section class="panel">
            <h2>Profile change history</h2>
            @forelse ($history as $entry)
                <article class="history-entry">
                    <strong>{{ $entry->actor }}</strong>
                    <time datetime="{{ \Carbon\CarbonImmutable::parse($entry->recorded_at)->toIso8601String() }}">{{ \Carbon\CarbonImmutable::parse($entry->recorded_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</time>
                    <ul>
                        @foreach (json_decode($entry->changes, true, flags: JSON_THROW_ON_ERROR) as $field => $change)
                            <li>{{ ucfirst(str_replace('_', ' ', $field)) }}: {{ $change['before'] ?? 'Blank' }} → {{ $change['after'] ?? 'Blank' }}</li>
                        @endforeach
                    </ul>
                </article>
            @empty
                <p>No profile edits recorded.</p>
            @endforelse
            @include('staff.pagination', ['page' => $history])
        </section>
    </div>
@endsection
