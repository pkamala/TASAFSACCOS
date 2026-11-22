@extends('layout')
@section('title', $original ? 'Replace chart account' : 'New chart account')
@section('content')
    <div class="page-intro">
        <a href="{{ $original ? route('staff.chart-accounts.show', $original->id) : route('staff.accounts.index') }}">Chart of accounts</a>
        <h1>{{ $original ? 'Replace chart account' : 'New chart account' }}</h1>
        @if ($original)<p>{{ $original->code }} · {{ $original->name }}. The original account will retire when the replacement is saved. Existing entries will keep the original account.</p>@endif
    </div>
    @include('form-errors')
    <section class="panel admin-profile">
        <form method="POST" action="{{ $original ? route('staff.chart-accounts.replace', $original->id) : route('staff.chart-accounts.store') }}" class="fields">
            @csrf
            @foreach (['code' => 'Account code', 'name' => 'Account name', 'name_sw' => 'Swahili name', 'name_en' => 'English name'] as $field => $label)
                @php($value = old($field, $original?->$field ?? ''))
                <label for="{{ $field }}">{{ $label }}</label>
                <input id="{{ $field }}" name="{{ $field }}" maxlength="{{ $field === 'code' ? 100 : 255 }}" value="{{ is_scalar($value) ? $value : '' }}" @required(in_array($field, ['code', 'name'], true))>
            @endforeach
            <label for="account_type_id">Classification</label>
            @php($selectedType = old('account_type_id', $original?->account_type_id))
            <select id="account_type_id" name="account_type_id" required>
                <option value="">Select an account type</option>
                @foreach ($types as $type)
                    <option value="{{ $type->id }}" @selected(is_scalar($selectedType) && (string) $selectedType === (string) $type->id)>{{ $type->category }} · {{ $type->name }}</option>
                @endforeach
            </select>
            <label for="reason">Reason or finance reference</label>
            <textarea id="reason" name="reason" maxlength="500" required>{{ is_string(old('reason')) ? old('reason') : '' }}</textarea>
            <button class="primary">{{ $original ? 'Create replacement and retire original' : 'Create account' }}</button>
        </form>
    </section>
@endsection
