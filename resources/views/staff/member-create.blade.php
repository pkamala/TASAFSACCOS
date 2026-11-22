@extends('layout')
@section('title', 'New member')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.members.index') }}">All members</a>
        <h1>New member</h1>
    </div>
    @include('form-errors')
    <section class="panel admin-profile">
        <form method="POST" action="{{ route('staff.members.store') }}" class="fields">
            @csrf
            @foreach ([
                'registration_no' => ['Membership number', 'text', 30],
                'first_name' => ['First name', 'text', 100],
                'middle_name' => ['Middle name', 'text', 100],
                'last_name' => ['Last name', 'text', 100],
                'email' => ['Email', 'email', 255],
                'phone' => ['Phone number', 'tel', 16],
            ] as $field => [$label, $type, $limit])
                <label for="{{ $field }}">{{ $label }} @if ($field === 'middle_name') <small>Optional</small> @endif</label>
                <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" maxlength="{{ $limit }}"
                    value="{{ is_string(old($field)) ? old($field) : '' }}" @required($field !== 'middle_name')>
            @endforeach
            <label for="joining_date">Joining date</label>
            <input id="joining_date" name="joining_date" type="date" min="1900-01-01" max="{{ $today }}"
                value="{{ is_string(old('joining_date')) ? old('joining_date') : $today }}" required>
            <button class="primary">Register member</button>
        </form>
    </section>
@endsection
