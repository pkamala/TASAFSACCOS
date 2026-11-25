@extends('layout')
@section('title', 'AGM registration')
@section('content')
    <div class="page-intro">
        <h1>AGM registration</h1>
    </div>
    @include('form-errors')
    <section class="panel stacked-panel">
        <h2>Create an AGM</h2>
        <form class="fields search" method="POST" action="{{ route('staff.agm.store') }}">
            @csrf
            <label for="title">Meeting title</label>
            <input id="title" name="title" maxlength="160" value="{{ is_string(old('title')) ? old('title') : '' }}" required>
            <label for="venue">Venue</label>
            <input id="venue" name="venue" maxlength="255" value="{{ is_string(old('venue')) ? old('venue') : '' }}" required>
            <label for="starts-at">Date and time (EAT)</label>
            <input id="starts-at" name="starts_at" type="datetime-local" value="{{ is_string(old('starts_at')) ? old('starts_at') : '' }}" required>
            <button class="primary">Create and open registration</button>
        </form>
    </section>
    <section class="panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="AGM meetings">
            <table>
                <thead><tr><th scope="col">Meeting</th><th scope="col">Venue</th><th scope="col">Starts (EAT)</th><th scope="col">Registration</th><th scope="col">Registered</th></tr></thead>
                <tbody>
                    @forelse ($meetings as $meeting)
                        <tr>
                            <th scope="row"><a href="{{ route('staff.agm.show', $meeting->id) }}">{{ $meeting->title }}</a></th>
                            <td>{{ $meeting->venue }}</td>
                            <td>{{ \Carbon\CarbonImmutable::parse($meeting->starts_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }}</td>
                            <td>{{ $meeting->registration_closed_at ? 'Closed' : 'Open' }}</td>
                            <td>{{ number_format($meeting->registrations) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No AGM meetings have been created.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $meetings])
    </section>
@endsection
