@extends('layout')
@section('title', 'Data imports')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.cutover-checklist') }}">Cutover checklist</a>
        <h1>Data imports</h1>
        <p>Use approved exports for one agreed closing date. Preview each upload and compare the totals with finance’s signed reports before applying it.</p>
    </div>
    @if (session('status')) <p class="status" role="status">{{ session('status') }}</p> @endif
    @include('form-errors')
    <section class="panel">
        <h2>Load the member register or chart of accounts</h2>
        <p>These first-load imports require an empty destination. Member status must be active or inactive. Use 1 for ledger accounts and 0 for other accounts.</p>
        <p><a href="{{ route('staff.data-imports.template', 'members') }}">Member register template</a> · <a href="{{ route('staff.data-imports.template', 'chart_accounts') }}">Chart of accounts template</a></p>
        <form method="POST" action="{{ route('staff.data-imports.preview') }}" enctype="multipart/form-data" class="fields search">
            @csrf
            <label for="kind">Import type</label>
            <select id="kind" name="kind">
                <option value="members" @selected(old('kind') === 'members')>Member register</option>
                <option value="chart_accounts" @selected(old('kind') === 'chart_accounts')>Chart of accounts</option>
            </select>
            <label for="sheet">Excel or CSV file</label>
            <input id="sheet" name="sheet" type="file" accept=".xlsx,.csv" required>
            <button class="primary">Preview import</button>
        </form>
    </section>
    @if ($preview)
        <section class="panel stacked-panel" id="initial-import-preview">
            <h2>{{ $preview['kind'] === 'members' ? 'Member register' : 'Chart of accounts' }} preview</h2>
            <p>{{ count($preview['rows']) }} valid records. Review the sample before applying.</p>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Import sample">
                <table>
                    <thead><tr><th scope="col">Reference</th><th scope="col">Name</th></tr></thead>
                    <tbody>
                        @foreach (array_slice($preview['rows'], 0, 10) as $row)
                            <tr><th scope="row">{{ $row['registration_no'] ?? $row['account_code'] }}</th><td>{{ $row['account_name'] ?? ($row['first_name'].' '.$row['last_name']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <form method="POST" action="{{ route('staff.data-imports.apply') }}" class="stacked-panel">
                @csrf
                <input type="hidden" name="token" value="{{ $preview['token'] }}">
                <button class="primary">Apply import</button>
            </form>
        </section>
    @endif
    <section class="panel stacked-panel">
        <h2>Import opening data</h2>
        <p>Use Excel (.xlsx) with a single worksheet or a CSV matching the template. Paste approved values in place of formulas and keep membership numbers and account codes as text.</p>
        <ol class="review-steps">
            <li><a href="{{ route('staff.verified-balance-import.index') }}">Import member savings and share balances</a> after the member register is loaded.</li>
            <li><a href="{{ route('staff.ledger-opening.index') }}">Import and post the opening trial balance</a> after the chart of accounts is loaded.</li>
            <li><a href="{{ route('staff.active-loans.index') }}">Migrate active loans</a> after verifying outstanding legacy principal.</li>
            <li><a href="{{ route('staff.ledger-financial-reports') }}">Review financial reports</a> against the approved opening totals.</li>
        </ol>
    </section>
@endsection
