@extends('layout')
@section('title', $event->title)
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.agm.index') }}">AGM registration</a>
        <h1>{{ $event->title }}</h1>
        <p>{{ $event->venue }} · {{ \Carbon\CarbonImmutable::parse($event->starts_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</p>
    </div>
    @if (session('status'))<p class="status" role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel stacked-panel">
        <dl class="summary">
            <div><dt>Registration</dt><dd>{{ $event->registration_closed_at ? 'Closed' : 'Open' }}</dd></div>
            <div><dt>Registered</dt><dd>{{ number_format($counts->registered) }}</dd></div>
            <div><dt>Checked in</dt><dd>{{ number_format($counts->checked_in) }}</dd></div>
        </dl>
        <form class="fields search print-hide" method="POST" action="{{ route('staff.agm.registration.update', $event->id) }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="open" value="{{ $event->registration_closed_at ? '1' : '0' }}">
            <button type="submit">{{ $event->registration_closed_at ? 'Reopen registration' : 'Close registration' }}</button>
            <a href="{{ route('staff.agm.export', $event->id) }}">Download register (CSV)</a>
        </form>
        <div class="table-wrap" tabindex="0" role="region" aria-label="AGM registration and attendance">
            <table>
                <thead><tr><th scope="col">Member</th><th scope="col">Email</th><th scope="col">Phone</th><th scope="col">Registered (EAT)</th><th scope="col">Attendance</th></tr></thead>
                <tbody>
                    @forelse ($registrations as $registration)
                        <tr>
                            <th scope="row">{{ $registration->registration_no }} · {{ $registration->member_name }}</th>
                            <td>{{ $registration->email }}</td>
                            <td>{{ $registration->phone ?? '—' }}</td>
                            <td>{{ \Carbon\CarbonImmutable::parse($registration->registered_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }}</td>
                            <td>
                                @if ($registration->checked_in_at)
                                    Checked in · {{ \Carbon\CarbonImmutable::parse($registration->checked_in_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT
                                @else
                                    <form method="POST" action="{{ route('staff.agm.check-in', [$event->id, $registration->id]) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" aria-label="Check in {{ $registration->member_name }}">Check in</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No registrations found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $registrations])
    </section>
@endsection
