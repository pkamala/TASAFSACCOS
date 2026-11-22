@extends('layout')
@section('title', 'Verified legacy loan balances')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-reconciliation.index') }}">Compare legacy balances</a>
        <h1>Verified legacy loan balances</h1>
        <p>Record finance-reviewed principal balances for a closed month. Monthly statement drafts use these figures for the selected month-end only.</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel">
        <h2>Prepare the report</h2>
        <ol class="review-steps">
            <li>Reconcile the legacy report and keep the signed-off evidence reference.</li>
            <li>Use one row per legacy loan ID, with the principal still outstanding in TZS at month-end. Enter zero for a cleared loan where the evidence confirms it.</li>
            <li>Include all applicable loans for each member. An unknown disbursement date requires review and a verified figure.</li>
        </ol>
        <p><a href="{{ route('staff.loan-reconciliation.download') }}">Download the legacy balance report CSV template</a></p>
        @if ($snapshots)
            <form method="POST" action="{{ route('staff.verified-legacy-loans.preview') }}" enctype="multipart/form-data" class="fields search">
                @csrf
                <label for="snapshot">Staged export</label>
                <select id="snapshot" name="snapshot" required>
                    @foreach ($snapshots as $hash)
                        <option value="{{ $hash }}" @selected($hash === old('snapshot', $preview['snapshot'] ?? $snapshots[0]))>{{ $hash }}</option>
                    @endforeach
                </select>
                <label for="period">Closed month</label>
                <input id="period" name="period" type="month" value="{{ is_string(old('period')) ? old('period') : substr($preview['asOf'] ?? '', 0, 7) }}" required>
                <label for="evidence">Reconciled report or sign-off reference</label>
                <textarea id="evidence" name="evidence" maxlength="500" required>{{ is_string(old('evidence')) ? old('evidence') : ($preview['evidence'] ?? '') }}</textarea>
                <label for="report">Reviewed legacy balances (CSV)</label>
                <input type="file" id="report" name="report" accept=".csv,text/csv" required>
                <button class="primary">Run dry run</button>
            </form>
        @else
            <p>No legacy loan snapshot has been staged.</p>
        @endif
    </section>
    @if ($preview)
        <section class="panel stacked-panel" id="preview">
            <h2>Dry-run results · {{ $preview['asOf'] }}</h2>
            <p>{{ number_format($ready) }} ready · {{ number_format($recorded) }} already recorded · {{ number_format($exceptions) }} exceptions</p>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Verified legacy loan preview">
                <table>
                    <thead><tr><th scope="col">Legacy loan ID</th><th scope="col">Loan reference</th><th scope="col">Member number</th><th scope="col" class="money">Principal (TZS)</th><th scope="col">Result</th></tr></thead>
                    <tbody>
                        @foreach ($preview['plan'] as $row)
                            <tr><th scope="row">{{ $row['source_id'] }}</th><td>{{ $row['reference'] ?? '—' }}</td><td>{{ $row['registration_no'] ?? '—' }}</td><td class="money">{{ number_format($row['principal_minor'] / 100, 2) }}</td><td>{{ $row['status'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($ready && ! $exceptions)
                <form method="POST" action="{{ route('staff.verified-legacy-loans.apply') }}" class="stacked-panel">
                    @csrf
                    <input type="hidden" name="token" value="{{ $preview['token'] }}">
                    <button class="primary">Record verified legacy balances</button>
                </form>
            @endif
        </section>
    @endif
    <section class="panel stacked-panel">
        <h2>Verification history</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Legacy balance verification history">
            <table>
                <thead><tr><th scope="col">Month-end</th><th scope="col">Loans</th><th scope="col">Evidence reference</th><th scope="col">Verified by</th><th scope="col">Verified at</th><th scope="col">Provenance</th></tr></thead>
                <tbody>
                    @forelse ($history as $item)
                        <tr>
                            <th scope="row">{{ $item->as_of }}</th><td>{{ $item->loan_count }}</td><td>{{ $item->evidence_reference }}</td><td>{{ $item->actor }}</td>
                            <td>{{ \Carbon\CarbonImmutable::parse($item->verified_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</td>
                            <td><details><summary>Checksums</summary><div>Export <code>{{ $item->source_sha256 }}</code></div><div>Report <code>{{ $item->report_sha256 }}</code></div></details></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No legacy loan balances verified.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $history])
    </section>
@endsection
