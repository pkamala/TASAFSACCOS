@extends('layout')
@section('title', 'AGM')
@section('content')
    <div class="page-intro">
        <a href="{{ route('member.account') }}">My membership</a>
        <h1>AGM</h1>
        <p>Register using the details already on your membership record.</p>
    </div>
    @if (session('status'))<p class="status" role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="AGM registration">
            <table>
                <thead><tr><th scope="col">Meeting</th><th scope="col">Venue</th><th scope="col">Starts (EAT)</th><th scope="col">Registration</th></tr></thead>
                <tbody>
                    @forelse ($meetings as $meeting)
                        <tr>
                            <th scope="row">{{ $meeting->title }}</th>
                            <td>{{ $meeting->venue }}</td>
                            <td>{{ \Carbon\CarbonImmutable::parse($meeting->starts_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }}</td>
                            <td>
                                @if ($meeting->registered_at)
                                    Registered
                                @elseif ($active)
                                    <form method="POST" action="{{ route('member.agm.register', $meeting->id) }}">
                                        @csrf
                                        <button class="primary" type="submit" aria-label="Register for {{ $meeting->title }}">Register</button>
                                    </form>
                                @else
                                    Active membership required
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No AGMs are currently open for registration.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $meetings])
    </section>
@endsection
