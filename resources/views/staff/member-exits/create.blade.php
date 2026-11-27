@extends('layout')
@section('title', 'Request membership exit')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.members.show', $profile->id) }}">Member profile</a>
        <h1>Membership exit</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }}</p>
    </div>
    @include('form-errors')
    <section class="panel admin-profile">
        @if ($latest)
            <p><a href="{{ route('staff.member-exits.show', $latest->id) }}">Latest request · {{ ucfirst($latest->status) }}</a></p>
        @endif
        @if (in_array($profile->status, ['active', 'inactive', 'deceased'], true) && ! in_array($latest->status ?? null, ['requested', 'approved', 'finalized'], true))
            <form class="fields" method="POST" action="{{ route('staff.member-exits.store', $profile->id) }}">
                @csrf
                <label for="reason">Reason for exit</label>
                <textarea id="reason" name="reason" maxlength="500" required>{{ is_string(old('reason')) ? old('reason') : '' }}</textarea>
                <button class="primary">Submit exit request</button>
            </form>
        @endif
    </section>
@endsection
