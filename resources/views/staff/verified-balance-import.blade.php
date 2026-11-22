@extends('layout')
@section('title', 'Import verified opening balances')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.members.index') }}">Members</a>
        <h1>Import verified opening balances</h1>
        <p>Load reviewed savings and share opening balances for multiple members.</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel">
        <h2>Prepare the file</h2>
        <ol class="review-steps">
            <li>Use one row per member. Enter their current membership number and, for imported members, their legacy member ID.</li>
            <li>Use the verified balance date in <code>YYYY-MM-DD</code> format and plain TZS amounts. Enter zero only if the reviewed statement confirms a zero balance.</li>
            <li>Include the statement or reconciliation reference for each row. An approved withdrawal or a recorded funds transaction locks the opening balance.</li>
        </ol>
        <p><a href="{{ route('staff.verified-balance-import.template') }}">Download verified opening balances CSV</a></p>
        <form method="POST" action="{{ route('staff.verified-balance-import.preview') }}" enctype="multipart/form-data" class="fields search">
            @csrf
            <label for="opening_balances">Reviewed opening balances CSV</label>
            <input type="file" id="opening_balances" name="opening_balances" accept=".csv,text/csv" required>
            <button class="primary">Run dry run</button>
        </form>
    </section>
    @if ($preview)
        <section class="panel stacked-panel" id="preview">
            <h2>Dry-run results</h2>
            <p>{{ number_format($ready) }} ready · {{ number_format($recorded) }} already recorded · {{ number_format($exceptions) }} exceptions</p>
            @if ($exceptions)
                <p>Correct the flagged rows and upload the file again before applying.</p>
            @endif
            <div class="table-wrap" tabindex="0" role="region" aria-label="Opening balance import preview">
                <table>
                    <thead><tr><th scope="col">CSV row</th><th scope="col">Member</th><th scope="col">Legacy ID</th><th scope="col">Balance date</th><th scope="col" class="money">Savings (TZS)</th><th scope="col" class="money">Shares (TZS)</th><th scope="col">Evidence reference</th><th scope="col">Result</th></tr></thead>
                    <tbody>
                        @foreach ($preview['plan'] as $row)
                            <tr>
                                <th scope="row">{{ $row['line'] }}</th>
                                <td>{{ $row['registration_no'] }} @if ($row['name']) · {{ $row['name'] }} @endif</td>
                                <td>{{ $row['legacy_member_id'] ?? '—' }}</td><td>{{ $row['as_of'] }}</td>
                                <td class="money">{{ number_format($row['savings_minor'] / 100, 2) }}</td><td class="money">{{ number_format($row['shares_minor'] / 100, 2) }}</td>
                                <td class="register-name">{{ $row['evidence_reference'] }}</td><td>{{ $row['status'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($ready && ! $exceptions)
                <form method="POST" action="{{ route('staff.verified-balance-import.apply') }}" class="stacked-panel">
                    @csrf
                    <input type="hidden" name="token" value="{{ $preview['token'] }}">
                    <button class="primary">Apply verified opening balances</button>
                </form>
            @endif
        </section>
    @endif
@endsection
