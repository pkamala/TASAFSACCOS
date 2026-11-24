@extends('layout')
@section('title', 'Annual budget')
@section('content')
    @php($money = fn ($minor) => number_format($minor / 100, 2))
    <div class="page-intro">
        <a href="{{ route('staff.ledger-financial-reports') }}">Financial reports</a>
        <h1>Annual budget</h1>
        <a class="print-hide" href="{{ route('staff.budgets.index', ['year' => $year, 'import' => 1]) }}" data-open-budget-import aria-haspopup="dialog">Import budget</a>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @unless ($importOpen) @include('form-errors') @endunless
    @include('staff.budget-import')
    <section class="panel print-hide">
        <form method="GET" action="{{ route('staff.budgets.index') }}" class="fields search">
            <label for="year">Budget year</label>
            <input id="year" name="year" type="number" min="1900" max="9998" value="{{ $year }}" required>
            <button>View budget</button>
        </form>
        @if ($accounts->isNotEmpty())
            <h2>Set budget for {{ $year }}</h2>
            <form method="POST" action="{{ route('staff.budgets.store') }}" class="fields search">
                @csrf
                <input type="hidden" name="year" value="{{ $year }}">
                <label for="budget-account">Income or expense account</label>
                <select id="budget-account" name="account" required>
                    <option value="">Select an account</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected(old('account') == $account->id)>{{ $account->code }} · {{ $account->name }}</option>
                    @endforeach
                </select>
                <label for="budget-amount">Annual budget amount (TZS)</label>
                <input id="budget-amount" name="amount" type="number" min="0" step="0.01" value="{{ is_scalar(old('amount')) ? old('amount') : '' }}" required>
                <button class="primary">Save budget line</button>
            </form>
        @else
            <p>Add an income or expense account in the chart of accounts to enter a budget.</p>
        @endif
    </section>
    <section class="panel stacked-panel">
        <h2>{{ $year }} budget and posted actuals</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Budget and actuals">
            <table>
                <thead><tr><th scope="col">Account</th><th scope="col">Category</th><th scope="col" class="money">Budget (TZS)</th><th scope="col" class="money">Actual (TZS)</th><th scope="col" class="money">Actual less budget (TZS)</th></tr></thead>
                <tbody>
                    @forelse ($lines as $line)
                        <tr>
                            <th scope="row">{{ $line->code }} · {{ $line->name }}</th>
                            <td>{{ $line->category }}</td>
                            <td class="money">{{ $line->amount_minor === null ? '—' : $money($line->amount_minor) }}</td>
                            <td class="money">{{ $money($line->actual_minor) }}</td>
                            <td class="money">{{ $line->amount_minor === null ? '—' : $money($line->actual_minor - $line->amount_minor) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No budget lines or posted activity for {{ $year }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
