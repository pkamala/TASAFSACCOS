<label>Member status
    <select name="member_status">
        <option value="">All members</option>
        @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'exited' => 'Withdrawn', 'deceased' => 'Deceased'] as $value => $label)
            <option value="{{ $value }}" @selected(request()->query('member_status') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>
