@extends('layout')
@section('title', 'Legacy loan corrections')
@section('content')
    <div class="page-intro">
        <h1>Legacy loan corrections</h1>
        <p>Review links flagged in the staged export before migrating loan balances.</p>
        <a href="{{ route('staff.loan-reconciliation.index') }}">Compare legacy balances</a>
    </div>

    @if ($snapshot)
        <section class="panel" aria-labelledby="checks">
            <h2 id="checks">What to resolve</h2>
            @if ($snapshots->count() > 1)
                <form method="GET" action="{{ route('staff.loan-corrections.index') }}" class="fields search">
                    <label for="snapshot">Staged export</label>
                    <select id="snapshot" name="snapshot">
                        @foreach ($snapshots as $hash)
                            <option value="{{ $hash }}" @selected($hash === $snapshot)>{{ $hash }}</option>
                        @endforeach
                    </select>
                    <button class="primary">Show snapshot</button>
                </form>
            @endif
            <p class="note">Snapshot SHA-256 <code class="snapshot-hash">{{ $snapshot }}</code></p>
            <p><a href="{{ route('staff.loan-corrections.worklist', ['snapshot' => $snapshot]) }}">Download unresolved records (CSV)</a></p>
            <p class="note">The worklist puts all flags for each record on one row. After verifying the evidence, transfer confirmed links to the legacy link corrections template.</p>
            <p class="note">Counts show flags still open after applied corrections. One row can appear under more than one check.</p>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Loan migration checks">
                <table class="correction-checks">
                    <thead><tr><th scope="col">Check</th><th scope="col">Flagged rows</th><th scope="col">How to verify</th></tr></thead>
                    <tbody>
                        @foreach ($checks as $flag => [$table, $label, $instruction])
                            <tr>
                                <th scope="row"><a href="{{ route('staff.loan-corrections.index', ['snapshot' => $snapshot, 'issue' => $flag]) }}#flagged-rows" @if ($flag === $issue) aria-current="true" @endif>{{ $label }}</a></th>
                                <td>{{ number_format($counts[$flag]) }}</td>
                                <td>{{ $instruction }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel stacked-panel" id="flagged-rows" aria-labelledby="flagged-heading">
            <h2 id="flagged-heading">{{ $checks[$issue][1] }}</h2>
            <p class="note">Source table <code>{{ $checks[$issue][0] }}</code>. Copy the snapshot and source ID into the corrections sheet.</p>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Flagged legacy rows">
                <table>
                    @if ($checks[$issue][0] === 'repayments')
                        <thead><tr><th scope="col">Source ID</th><th scope="col">Receipt ID</th><th scope="col">Payroll record ID</th><th scope="col">Direct loan ID</th><th scope="col">Installment ID</th><th scope="col">Loan on original installment</th></tr></thead>
                    @else
                        <thead><tr><th scope="col">Source ID</th><th scope="col">Referenced ID</th></tr></thead>
                    @endif
                    <tbody>
                        @forelse ($exceptions as $entry)
                            @php($data = $entry['data'])
                            <tr>
                                <th scope="row">{{ $entry['id'] }}</th>
                                @if ($checks[$issue][0] === 'repayments')
                                    <td>{{ $data['payment_id'] ?? '—' }}</td>
                                    <td>{{ $data['monthly_id'] ?? '—' }}</td>
                                    <td>{{ $data['rploan_id'] ?? '—' }}</td>
                                    <td>{{ $data['loanschedule_id'] ?? '—' }}</td>
                                    <td>{{ $scheduleLoans->get($data['loanschedule_id'] ?? '') ?? '—' }}</td>
                                @else
                                    <td>{{ $data[substr($issue, strlen('unresolved_'))] ?? '—' }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $checks[$issue][0] === 'repayments' ? 6 : 2 }}">No rows flagged for this check.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @include('staff.pagination', ['page' => $exceptions])
        </section>
        <section class="panel stacked-panel" id="correction-upload" aria-labelledby="upload-heading">
            <h2 id="upload-heading">Upload reviewed corrections</h2>
            @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
            @include('form-errors')
            <p>Leave the confirmed installment ID blank for an installment correction. For a repayment correction, provide both the confirmed loan and its installment. A corrected installment can be included in the same file.</p>
            <form method="POST" action="{{ route('staff.loan-corrections.preview') }}" enctype="multipart/form-data" class="fields search">
                @csrf
                <input type="hidden" name="snapshot" value="{{ $snapshot }}">
                <label for="corrections">Legacy link corrections CSV</label>
                <input id="corrections" type="file" name="corrections" accept=".csv,text/csv" required>
                <button class="primary">Run dry run</button>
            </form>
            @if ($preview)
                <h3>Dry run passed</h3>
                <p>{{ number_format(count($preview['rows'])) }} corrections validated. Review the mappings before applying them.</p>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Validated corrections">
                    <table>
                        <thead><tr><th scope="col">Source</th><th scope="col">Source ID</th><th scope="col">Confirmed loan</th><th scope="col">Confirmed installment</th><th scope="col">Evidence reference</th><th scope="col">Reviewer</th><th scope="col">Review date</th></tr></thead>
                        <tbody>
                            @foreach ($preview['rows'] as $row)
                                <tr><td>{{ $row['source_table'] }}</td><th scope="row">{{ $row['source_id'] }}</th><td>{{ $row['loan_id'] }}</td><td>{{ $row['schedule_id'] ?? '—' }}</td><td>{{ $row['evidence_reference'] }}</td><td>{{ $row['reviewed_by'] }}</td><td>{{ $row['reviewed_on'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <form method="POST" action="{{ route('staff.loan-corrections.apply') }}" class="stacked-panel">
                    @csrf
                    <input type="hidden" name="token" value="{{ $preview['token'] }}">
                    <button class="primary">Apply validated corrections</button>
                </form>
            @endif
        </section>
        @if ($applied->isNotEmpty() || $applied->hasPages())
            <section class="panel stacked-panel" aria-labelledby="applied-heading">
                <h2 id="applied-heading">Applied corrections</h2>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Applied corrections">
                    <table>
                        <thead><tr><th scope="col">Source</th><th scope="col">Source ID</th><th scope="col">Loan</th><th scope="col">Installment</th><th scope="col">Evidence reference</th><th scope="col">Reviewed by</th><th scope="col">Review date</th><th scope="col">Applied by</th><th scope="col">Applied at</th></tr></thead>
                        <tbody>
                            @foreach ($applied as $row)
                                <tr><td>{{ $row->source_table }}</td><th scope="row">{{ $row->source_id }}</th><td>{{ $row->loan_id }}</td><td>{{ $row->schedule_id ?? '—' }}</td><td>{{ $row->evidence_reference }}</td><td>{{ $row->reviewed_by }}</td><td>{{ $row->reviewed_on }}</td><td>{{ $row->actor }}</td><td>{{ \Carbon\CarbonImmutable::parse($row->applied_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @include('staff.pagination', ['page' => $applied])
            </section>
        @endif
    @else
        <section class="panel"><p>No legacy loan snapshot has been staged. The templates are available below.</p></section>
    @endif

    <section class="panel stacked-panel" aria-labelledby="templates">
        <h2 id="templates">CSV templates</h2>
        <ul class="template-list">
            @foreach ($templates as $key => [$file, $label, $description])
                <li><a href="{{ route('staff.loan-corrections.download', $key) }}">{{ $label }} (CSV)</a><span>{{ $description }}</span></li>
            @endforeach
        </ul>
    </section>
    <section class="panel stacked-panel" aria-labelledby="review">
        <h2 id="review">Before migrating balances</h2>
        <ol class="review-steps">
            <li>Use the corrections sheet for the staged export. Record the evidence reference, reviewer, and review date for each confirmed link. For member or product mismatches, record the verified ID in the evidence notes and flag the row for finance review.</li>
            <li>Use the loan, installment, and allocation templates for separate supplemental records. Keep references stable, enter dates as <code>YYYY-MM-DD</code>, and use plain TZS amounts such as <code>1250000.00</code>.</li>
            <li>Run a dry run and apply the validated links. Reconcile scheduled principal, applied principal, and outstanding balances to the legacy reports.</li>
            <li>Approve the reconciled totals before posting balances or using them in monthly member emails. Preserve the original export and the reviewed corrections as the audit record.</li>
        </ol>
    </section>
@endsection
