@extends('layout')
@section('title', 'Next of kin')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.members.show', $profile->id) }}">Member profile</a>
        <h1>Next of kin</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }}</p>
    </div>
    @if (session('status')) <p class="status" role="status">{{ session('status') }}</p> @endif
    @include('form-errors')
    <section class="panel admin-profile">
        <h2>Add next of kin</h2>
        <form method="POST" action="{{ route('staff.members.next-of-kin.store', $profile->id) }}" class="fields">
            @csrf
            @include('staff.next-of-kin-fields', ['record' => null])
            <button class="primary">Add contact</button>
        </form>
    </section>
    <section class="panel stacked-panel">
        <h2>Recorded contacts</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Next of kin contacts">
            <table>
                <thead><tr><th scope="col">Name</th><th scope="col">Relationship</th><th scope="col">Phone</th><th scope="col">Email</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                    @forelse ($contacts as $contact)
                        <tr>
                            <th scope="row">{{ $contact->full_name }}</th><td>{{ $contact->relationship }}</td><td>{{ $contact->phone }}</td>
                            <td>{{ $contact->email ?? '—' }}</td>
                            <td><details @if (old('edit_contact') == $contact->id) open @endif>
                                <summary>Edit contact</summary>
                                <form method="POST" action="{{ route('staff.members.next-of-kin.update', [$profile->id, $contact->id]) }}" class="fields">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="edit_contact" value="{{ $contact->id }}">
                                    @include('staff.next-of-kin-fields', ['record' => $contact])
                                    <button class="primary">Save contact</button>
                                </form>
                            </details></td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No next of kin contacts have been recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $contacts])
    </section>
@endsection
