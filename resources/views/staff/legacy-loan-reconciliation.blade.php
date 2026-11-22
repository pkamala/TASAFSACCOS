@extends('layout')
@section('title', 'Legacy loan reconciliation')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-corrections.index') }}">Loan corrections</a>
        <h1>Compare legacy loan balances</h1>
        <a href="{{ route('staff.verified-legacy-loans.index') }}">Import reviewed month-end loan balances</a>
        <p>Compare principal balances from a legacy report against principal reconstructed from staged data and applied corrections.</p>
    </div>
    <section class="panel">
        <h2>Legacy report</h2>
        <p><a href="{{ route('staff.loan-reconciliation.download') }}">Download the balance report CSV template</a></p>
        @include('form-errors')
        @if ($snapshots)
            <form class="fields search" method="POST" action="{{ route('staff.loan-reconciliation.compare') }}" enctype="multipart/form-data">
                @csrf
                <label for="snapshot">Staged export</label>
                <select id="snapshot" name="snapshot">
                    @foreach ($snapshots as $hash)
                        <option value="{{ $hash }}" @selected($hash === old('snapshot', $snapshot ?? $snapshots[0]))>{{ $hash }}</option>
                    @endforeach
                </select>
                <label for="as_of">Report date</label>
                <input type="date" id="as_of" name="as_of" value="{{ is_string(old('as_of', $asOf ?? '')) ? old('as_of', $asOf ?? '') : '' }}" required>
                <label for="report">Principal balance report (CSV)</label>
                <input type="file" id="report" name="report" accept=".csv,text/csv" required>
                <button class="primary">Compare balances</button>
            </form>
        @else
            <p>No legacy loan snapshot has been staged.</p>
        @endif
        <p class="note">Use one row per legacy loan ID, with principal-only balances in TZS as of the selected date.</p>
    </section>
    @if ($comparison !== null)
        @php($format = fn ($value) => $value === null ? '—' : number_format($value / 100, 2))
        <section class="panel stacked-panel" aria-labelledby="results">
            <h2 id="results">Comparison as of {{ $asOf }}</h2>
            <p>Reconstructed principal equals the original principal in the export less linked principal repayments through the report date. The difference is the reconstructed balance minus the reported balance.</p>
            <p class="note">{{ number_format($unlinked) }} repayment records across this snapshot still have no confirmed loan link. These records are outside the reconstruction. Check flagged dates or amounts, restructuring, and other adjustments before approving opening balances.</p>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Loan balance comparison">
                <table>
                    <thead><tr><th scope="col">Legacy loan ID</th><th scope="col">Reference</th><th scope="col" class="money">Reported principal (TZS)</th><th scope="col" class="money">Reconstructed principal (TZS)</th><th scope="col" class="money">Difference (TZS)</th><th scope="col">Review</th></tr></thead>
                    <tbody>
                        @foreach ($comparison as $row)
                            <tr>
                                <th scope="row">{{ $row['id'] }}</th><td>{{ $row['code'] ?? '—' }}</td>
                                <td class="money">{{ $format($row['reported']) }}</td>
                                <td class="money">{{ $format($row['reconstructed']) }}</td>
                                <td class="money">{{ $format($row['difference']) }}</td>
                                <td>{{ $row['review'] }} @if ($row['excluded']) <small>({{ number_format($row['excluded']) }} excluded)</small> @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
