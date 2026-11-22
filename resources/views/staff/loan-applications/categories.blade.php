@extends('layout')
@section('title', 'Loan categories')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-pricing.edit') }}">Loan pricing</a>
        <h1>Loan categories</h1>
        <p>Categories classify loan applications. Set interest and charges for each category in Loan pricing. Savings and share minimums apply across categories.</p>
    </div>
    @if (session('status')) <p class="status" role="status">{{ session('status') }}</p> @endif
    @include('form-errors')
    <section class="panel">
        <form class="fields search" method="POST" action="{{ route('staff.loan-categories.store') }}">
            @csrf
            <label for="name">Category name</label>
            <input id="name" name="name" maxlength="80" value="{{ is_string(old('name')) ? old('name') : '' }}" required>
            <button class="primary">Add loan category</button>
        </form>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Loan categories">
            <table>
                <thead><tr><th scope="col">Category</th><th scope="col">Added by</th><th scope="col">Date added</th></tr></thead>
                <tbody>
                    @foreach ($categories as $category)
                        <tr><th scope="row">{{ $category->name }}</th><td>{{ $category->actor ?? 'System' }}</td>
                            <td>{{ \Carbon\CarbonImmutable::parse($category->created_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $categories])
    </section>
@endsection
