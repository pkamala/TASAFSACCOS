<label for="category">Loan category</label>
<select id="category" name="category">
    @foreach ($categories as $option)
        <option value="{{ $option->id }}" @selected($option->id === $category)>{{ $option->name }}</option>
    @endforeach
</select>
