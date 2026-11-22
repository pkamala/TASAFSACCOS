@extends('layout')
@section('title', 'Board roles')
@section('content')
    <div class="page-intro">
        <h1>Board roles</h1>
        <p>Assign each role to an active member with a sign-in account. A replacement keeps their own account, and the outgoing officer returns to regular member access.</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <div class="profile-grid">
        @foreach ($roles as $key => $label)
            @php($holder = $holders->get($key))
            <section class="panel">
                <h2>{{ $label }}</h2>
                <p>@if ($holder){{ $holder->name }} @if ($holder->registration_no) · {{ $holder->registration_no }} @endif @else Vacant @endif</p>
                <form class="fields" method="POST" action="{{ route('staff.board-roles.update', $key) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="current_holder" value="{{ $holder->id ?? 0 }}">
                    <label for="registration-{{ $key }}">New officeholder’s membership number</label>
                    <input id="registration-{{ $key }}" name="registration_no" maxlength="30" required>
                    <label for="reason-{{ $key }}">Reason or appointment record</label>
                    <textarea id="reason-{{ $key }}" name="reason" maxlength="500" rows="3" required></textarea>
                    <button name="operation" value="assign" class="primary">Assign {{ $label }}</button>
                    @if ($holder)
                        <button name="operation" value="vacate" class="quiet" formnovalidate>Mark role vacant</button>
                    @endif
                </form>
            </section>
        @endforeach
    </div>
    <section class="panel stacked-panel">
        <h2>Assignment history</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Board role history">
            <table>
                <thead><tr><th scope="col">Role</th><th scope="col">Outgoing</th><th scope="col">Incoming</th><th scope="col">Changed by</th><th scope="col">Changed at</th><th scope="col">Reason</th></tr></thead>
                <tbody>
                    @forelse ($history as $entry)
                        <tr><th scope="row">{{ $roles[$entry->role] }}</th><td>{{ $entry->previous_name ?? 'Vacant' }}</td><td>{{ $entry->next_name ?? 'Vacant' }}</td><td>{{ $entry->actor_name }}</td><td>{{ \Carbon\CarbonImmutable::parse($entry->changed_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</td><td>{{ $entry->reason }}</td></tr>
                    @empty
                        <tr><td colspan="6">No changes recorded through this page.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $history])
    </section>
@endsection
