@extends('layout')
@section('title', 'Verified savings and shares')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.members.show', $profile->id) }}">Member profile</a>
        <h1>Verified savings and shares</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }}</p>
        <p>Loan minimums check: {{ $eligibility['reason'] ?? 'Passed' }}</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel admin-profile">
        <h2>Record reviewed balances</h2>
        <p>Copy the savings and share value from the verified member statement. This records evidence for eligibility.</p>
        <form method="POST" action="{{ route('staff.member-balances.store', $profile->id) }}" class="fields">
            @csrf
            @foreach (['savings' => 'Savings balance (TZS)', 'shares' => 'Share value (TZS)'] as $field => $label)
                <label for="{{ $field }}">{{ $label }}</label>
                <input type="number" id="{{ $field }}" name="{{ $field }}" min="0" step="0.01" value="{{ is_scalar(old($field)) ? old($field) : '' }}" required>
            @endforeach
            <label for="as_of">Balance date</label>
            <input type="date" id="as_of" name="as_of" required>
            <label for="evidence_reference">Statement or reconciliation reference</label>
            <textarea id="evidence_reference" name="evidence_reference" maxlength="500" required>{{ is_string(old('evidence_reference')) ? old('evidence_reference') : '' }}</textarea>
            <button class="primary">Save verified balances</button>
        </form>
    </section>
    <section class="panel stacked-panel">
        <h2>Balance history</h2>
        <a href="{{ route('staff.verified-balance-import.index') }}">Bulk opening balance import</a>
        <div class="table-wrap"><table>
            <thead><tr><th scope="col">Balance date</th><th scope="col">Savings (TZS)</th><th scope="col">Shares (TZS)</th><th scope="col">Evidence</th><th scope="col">Recorded by</th><th scope="col">Recorded at</th></tr></thead>
            <tbody>
                @forelse ($history as $row)
                    <tr><th scope="row">{{ $row->as_of }}</th><td>{{ number_format($row->savings_minor / 100, 2) }}</td><td>{{ number_format($row->shares_minor / 100, 2) }}</td><td>{{ $row->evidence_reference }} @if ($row->import_sha256)<details><summary>Import checksum</summary><code>{{ $row->import_sha256 }}</code></details>@endif</td><td>{{ $row->actor }}</td><td>{{ $row->recorded_at }}</td></tr>
                @empty
                    <tr><td colspan="6">No verified balances recorded.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        @include('staff.pagination', ['page' => $history])
    </section>
@endsection
