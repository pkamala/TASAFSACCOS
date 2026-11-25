@extends('layout')
@section('title', 'Loan categories')
@section('content')
    @php($renameError = $errors->first('category_name'))
    <div class="page-intro">
        <a href="{{ route('staff.loan-pricing.edit') }}">Loan pricing</a>
        <h1>Loan categories</h1>
        <p>Categories classify loan applications. Set interest and charges for each category in Loan pricing. Savings and share minimums apply across categories.</p>
    </div>
    @if (session('status')) <p class="status" role="status">{{ session('status') }}</p> @endif
    @if (! $editing || ! $renameError)
        @include('form-errors')
    @endif
    <section class="panel">
        <form class="fields search" method="POST" action="{{ route('staff.loan-categories.store') }}">
            @csrf
            <label for="name">Category name</label>
            <input id="name" name="name" maxlength="80" value="{{ is_string(old('name')) ? old('name') : '' }}" required>
            <button class="primary">Add loan category</button>
        </form>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Loan categories">
            <table>
                <thead><tr><th scope="col">Category</th><th scope="col">Added by</th><th scope="col">Date added</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                    @foreach ($categories as $category)
                        <tr><th scope="row">{{ $category->name }}</th><td>{{ $category->actor ?? 'System' }}</td>
                            <td>{{ \Carbon\CarbonImmutable::parse($category->created_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</td>
                            <td><a href="{{ route('staff.loan-categories.index', ['edit' => $category->id, ...request()->except('edit')]) }}" aria-label="Edit {{ $category->name }}">Edit</a></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $categories])
    </section>
    @if ($editing)
        <dialog class="panel stacked-panel category-edit-dialog" id="category-edit" aria-labelledby="category-edit-title" open>
            <h2 id="category-edit-title">Edit loan category</h2>
            @if ($renameError)
                <p class="errors" role="alert" id="category-name-error">{{ $renameError }}</p>
            @endif
            <form class="fields search" method="POST" action="{{ route('staff.loan-categories.update', $editing->id) }}">
                @csrf
                @method('PATCH')
                <label for="category-name">Category name</label>
                <input id="category-name" name="category_name" maxlength="80" value="{{ is_string(old('category_name')) ? old('category_name') : $editing->name }}" required autofocus
                    @if ($renameError) aria-invalid="true" aria-describedby="category-name-error" @endif>
                <button class="primary">Save name</button>
                <a id="cancel-category-edit" href="{{ route('staff.loan-categories.index', request()->except('edit')) }}">Cancel</a>
            </form>
        </dialog>
        <script src="{{ asset('saccos-category-edit.js') }}" defer></script>
    @endif
@endsection
