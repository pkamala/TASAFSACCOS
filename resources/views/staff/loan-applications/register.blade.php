@extends('layout')
@section('title', 'Printable loan register')
@section('content')
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-applications.queue') }}">Loan review</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>Loan register</h1>
        <p>{{ $source === 'applications' ? 'New loan applications' : 'Legacy loans' }} · {{ $status !== null ? ucfirst($status) : 'All statuses' }} @if ($categoryId) · {{ $categories->firstWhere('id', $categoryId)->name }} @endif · {{ number_format($loans->count()) }} records</p>
        <p class="note">Generated {{ $printedOn }} EAT @if ($source === 'legacy') · Statuses and principal from the legacy export @endif</p>
    </div>
    <section class="panel print-hide">
        <form class="fields search" method="GET" action="{{ route('staff.loan-register') }}">
            @include('form-errors')
            <label for="status">Loan status</label>
            <input type="hidden" name="source" value="{{ $source }}">
            <select id="status" name="status">
                <option value="">All statuses</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option }}" @selected($option === $status)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
            @if ($source === 'applications')
                <label for="category">Loan category</label>
                <select id="category" name="category">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($category->id === $categoryId)>{{ $category->name }}</option>
                    @endforeach
                </select>
            @endif
            <button class="primary">Filter loans</button>
        </form>
        @can('manage-members')<p><a href="{{ route('staff.loan-register', ['source' => $source === 'legacy' ? 'applications' : 'legacy']) }}">{{ $source === 'legacy' ? 'View new loan applications' : 'View legacy loans' }}</a></p>@endcan
        <button type="button" class="primary" onclick="window.print()">Print register</button>
    </section>
    <section class="panel stacked-panel print-register">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Loan register">
            <table data-table-pages>
                <thead><tr>
                    <th scope="col">Reference</th><th scope="col">Member</th><th scope="col">Status</th>
                    <th scope="col" class="money">Principal (TZS)</th><th scope="col">Term (months)</th>
                    @if ($source === 'applications')
                        <th scope="col">Category</th><th scope="col">Annual interest</th><th scope="col">Method</th>
                        <th scope="col" class="money">Financed charges (TZS)</th><th scope="col">First due date</th>
                    @endif
                </tr></thead>
                <tbody>
                    @forelse ($loans as $loan)
                        <tr>
                            <th scope="row">
                                @if ($source === 'applications')
                                    <a href="{{ route('staff.loan-applications.show', [$loan->member_id, $loan->id]) }}">{{ $loan->id }}</a>
                                @else
                                    <a href="{{ route('staff.legacy-loans.show', [$loan->member_id, $loan->id]) }}">{{ $loan->reference ?: $loan->id }}</a>
                                @endif
                            </th>
                            <td class="register-name">{{ $loan->registration_no }} · {{ $loan->first_name }} {{ $loan->last_name }}</td>
                            <td>{{ ucfirst($loan->status ?: 'Unknown') }}</td>
                            <td class="money">{{ is_numeric($loan->principal) ? number_format((float) $loan->principal, 2) : '—' }}</td>
                            <td>{{ $loan->term_months ?? '—' }} @if ($source === 'applications' && $loan->interest_method === 'scheduled') installments @endif</td>
                            @if ($source === 'applications')
                                <td>{{ $loan->category_name ?? 'Uncategorized' }}</td>
                                <td>{{ $loan->interest_method === 'scheduled' ? '—' : number_format((float) $loan->rate, 2).'%' }}</td>
                                <td>{{ match ($loan->interest_method) { 'scheduled' => 'Migrated schedule', 'flat' => 'Flat rate', default => 'Reducing balance' } }}</td>
                                <td class="money">{{ number_format((float) $loan->charges, 2) }}</td>
                                <td>{{ $loan->first_due_date }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $source === 'applications' ? 10 : 5 }}">No loans match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
