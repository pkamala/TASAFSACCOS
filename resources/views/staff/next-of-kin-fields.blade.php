@php($showOld = old('edit_contact') == $record?->id)
@foreach ([
    'full_name' => ['Full name', 'text', 200],
    'relationship' => ['Relationship', 'text', 80],
    'phone' => ['Phone number', 'tel', 16],
    'email' => ['Email', 'email', 255],
] as $field => [$label, $type, $limit])
    @php($id = 'kin-'.($record?->id ?? 'new').'-'.$field)
    @php($value = $showOld ? old($field, $record?->$field) : $record?->$field)
    <label for="{{ $id }}">{{ $label }} @if ($field === 'email') <small>Optional</small> @endif</label>
    <input id="{{ $id }}" name="{{ $field }}" type="{{ $type }}" maxlength="{{ $limit }}"
        value="{{ is_string($value) ? $value : '' }}" @required($field !== 'email')>
@endforeach
