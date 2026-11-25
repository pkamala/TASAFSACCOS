@extends('layout')
@section('title', 'Savings interest and withholding rates')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.member-exits.index') }}">Member exits</a>
        <h1>Savings interest and withholding rates</h1>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel">
        <form class="fields search" method="POST" action="{{ route('staff.exit-settlement-rates.update') }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="current_revision" value="{{ $rates->id }}">
            <label for="savings_interest_rate">Annual savings interest (%)</label>
            <input type="number" id="savings_interest_rate" name="savings_interest_rate" min="0" max="100" step="0.01" value="{{ old('savings_interest_rate', $rates->savings_interest_basis_points / 100) }}" required>
            <label for="withholding_rate">Withholding on gross savings interest (%)</label>
            <input type="number" id="withholding_rate" name="withholding_rate" min="0" max="100" step="0.01" value="{{ old('withholding_rate', $rates->withholding_basis_points / 100) }}" required>
            <button class="primary">Save rates</button>
        </form>
    </section>
@endsection
