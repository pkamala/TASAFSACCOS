@extends('layout')
@section('title', 'Loan pricing')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-applications.queue') }}">Loan review</a>
        <h1>Loan pricing</h1>
        <a href="{{ route('staff.membership-minimums.index') }}">Loan savings and share minimums</a>
        <a href="{{ route('staff.loan-categories.index') }}">Loan categories</a>
        <p>New applications use the current pricing for their category. Each saved application keeps its original pricing revision.</p>
    </div>
    <section class="panel admin-profile">
        <h2>Pricing for new applications</h2>
        <form class="fields search" method="GET" action="{{ route('staff.loan-pricing.edit') }}">
            @include('staff.loan-applications.category-picker')
            <button type="submit">Load category pricing</button>
        </form>
        @if ($current && $current->loan_category_id === null)
            <p>The selected category uses the previous shared pricing until its own pricing is saved.</p>
        @endif
        @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
        @include('form-errors')
        <form class="fields" method="POST" action="{{ route('staff.loan-pricing.update') }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="loan_category_id" value="{{ $category }}">
            <input type="hidden" name="current_revision" value="{{ $current->id ?? 0 }}">
            <label for="interest_method">Interest method</label>
            <select id="interest_method" name="interest_method" required>
                <option value="reducing" @selected(old('interest_method', $current->interest_method ?? '') === 'reducing')>Reducing balance</option>
                <option value="flat" @selected(old('interest_method', $current->interest_method ?? '') === 'flat')>Flat rate</option>
            </select>
            @foreach (['annual_rate' => ['Annual interest rate (%)', 'annual_basis_points', 100], 'insurance_rate' => ['Contingency Fund (% of requested principal)', 'insurance_basis_points', 100], 'processing_fee' => ['Processing fee (TZS)', 'processing_fee_minor', 100000000000], 'other_charges' => ['Other fixed charges (TZS)', 'other_charges_minor', 100000000000]] as $field => [$label, $column, $max])
                @php($value = old($field, $current ? $current->$column / 100 : ''))
                <label for="{{ $field }}">{{ $label }}</label>
                <input type="number" id="{{ $field }}" name="{{ $field }}" min="0" max="{{ $max }}" step="0.01" value="{{ is_scalar($value) ? $value : '' }}" required>
            @endforeach
            <label for="reason">Reason for change</label>
            <textarea id="reason" name="reason" maxlength="500" rows="3" required>{{ is_string(old('reason')) ? old('reason') : '' }}</textarea>
            <button class="primary">Save pricing</button>
        </form>
    </section>
    <section class="panel stacked-panel">
        <h2>Pricing history</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Pricing history">
            <table>
                <thead><tr><th scope="col">Revision</th><th scope="col">Scope</th><th scope="col">Annual rate</th><th scope="col">Method</th><th scope="col">Contingency Fund</th><th scope="col">Processing (TZS)</th><th scope="col">Other charges (TZS)</th><th scope="col">Changed by</th><th scope="col">Changed at</th><th scope="col">Reason</th></tr></thead>
                <tbody>
                    @forelse ($history as $row)
                        <tr><th scope="row">{{ $row->id }}</th><td>{{ $row->loan_category_id ? 'Selected category' : 'Previous shared pricing' }}</td><td>{{ $row->annual_basis_points / 100 }}%</td><td>{{ $row->interest_method === 'flat' ? 'Flat rate' : 'Reducing balance' }}</td><td>{{ $row->insurance_basis_points / 100 }}%</td><td>{{ number_format($row->processing_fee_minor / 100, 2) }}</td><td>{{ number_format($row->other_charges_minor / 100, 2) }}</td><td>{{ $row->actor }}</td><td>{{ \Carbon\CarbonImmutable::parse($row->changed_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</td><td>{{ $row->reason }}</td></tr>
                    @empty
                        <tr><td colspan="10">No pricing revisions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $history])
    </section>
@endsection
