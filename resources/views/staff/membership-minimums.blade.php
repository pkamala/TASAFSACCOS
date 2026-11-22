@extends('layout')
@section('title', 'Loan eligibility minimums')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-pricing.edit') }}">Loan pricing</a>
        <h1>Loan eligibility minimums</h1>
        <p>Members must meet both minimums in their verified balances to qualify for a new loan.</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel admin-profile">
        <form method="POST" action="{{ route('staff.membership-minimums.update') }}" class="fields">
            @csrf
            @method('PATCH')
            <input type="hidden" name="current_revision" value="{{ $current->id ?? 0 }}">
            @foreach (['savings' => ['Minimum savings (TZS)', 'min_savings_minor'], 'shares' => ['Minimum share value (TZS)', 'min_shares_minor']] as $field => [$label, $column])
                @php($value = old($field, $current ? $current->$column / 100 : ''))
                <label for="{{ $field }}">{{ $label }}</label>
                <input type="number" id="{{ $field }}" name="{{ $field }}" min="0" step="0.01" value="{{ is_scalar($value) ? $value : '' }}" required>
            @endforeach
            <label for="reason">Reason for change</label>
            <textarea id="reason" name="reason" maxlength="500" required>{{ is_string(old('reason')) ? old('reason') : '' }}</textarea>
            <button class="primary">Save minimums</button>
        </form>
    </section>
    <section class="panel stacked-panel">
        <h2>Minimums history</h2>
        <div class="table-wrap"><table>
            <thead><tr><th scope="col">Revision</th><th scope="col">Savings (TZS)</th><th scope="col">Shares (TZS)</th><th scope="col">Changed by</th><th scope="col">Changed at</th><th scope="col">Reason</th></tr></thead>
            <tbody>
                @forelse ($history as $row)
                    <tr><th scope="row">{{ $row->id }}</th><td>{{ number_format($row->min_savings_minor / 100, 2) }}</td><td>{{ number_format($row->min_shares_minor / 100, 2) }}</td><td>{{ $row->actor }}</td><td>{{ $row->changed_at }}</td><td>{{ $row->reason }}</td></tr>
                @empty
                    <tr><td colspan="6">No minimums configured.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        @include('staff.pagination', ['page' => $history])
    </section>
@endsection
