@extends('layout')
@section('title', 'Active loan migration')
@section('content')
    @php($money = fn ($value) => number_format($value / 100, 2))
    <div class="page-intro">
        <a href="{{ route('staff.data-imports.index') }}">Data imports</a>
        <a href="{{ route('staff.cutover-checklist') }}">Cutover checklist</a>
        <h1>Active loan migration</h1>
        <p>Carry verified legacy loan installments into payroll and member statements at cutover.</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel stacked-panel">
        <h2>Prepare the import</h2>
        <ol class="review-steps">
            <li><a href="{{ route('staff.ledger-opening.index') }}">Post the opening trial balance</a> with the reconciled loan receivable.</li>
            <li><a href="{{ route('staff.verified-legacy-loans.index') }}">Verify legacy principal</a> as of the day before cutover.</li>
            <li><a href="{{ route('staff.active-loans.template') }}">Download the schedule template</a>. Use one row per remaining due date per legacy loan. Include only unpaid principal and interest, using due dates on or after cutover. Use the approved collection date for arrears.</li>
        </ol>
        @if (! $cutover || $snapshots === [])
            <p>Upload becomes available when an opening journal is posted and a legacy loan snapshot has been staged.</p>
        @else
            <p>Ledger cutover {{ $cutover }}. Apply the migration before preparing payroll for an affected month.</p>
            <p>Verified loans still awaiting migration: {{ number_format($pending->loans) }} · TZS {{ $money($pending->principal_minor) }} principal.</p>
            <form method="POST" action="{{ route('staff.active-loans.preview') }}" enctype="multipart/form-data" class="fields">
                @csrf
                <label for="snapshot">Legacy loan snapshot</label>
                <select id="snapshot" name="snapshot" required>
                    @foreach ($snapshots as $snapshot)
                        <option value="{{ $snapshot }}" @selected(old('snapshot', $preview['snapshot'] ?? '') === $snapshot)>{{ $snapshot }}</option>
                    @endforeach
                </select>
                <label for="schedule">Remaining installments (CSV or single-sheet Excel)</label>
                <input id="schedule" name="schedule" type="file" accept=".csv,.xlsx" required>
                <label for="reference">Finance approval reference</label>
                <input id="reference" name="reference" value="{{ is_string(old('reference')) ? old('reference') : ($preview['reference'] ?? '') }}" maxlength="500" required>
                <button class="primary">Preview migration</button>
            </form>
        @endif
    </section>

    @if ($preview)
        <section class="panel stacked-panel" id="preview">
            <h2>Review remaining installments</h2>
            <p>{{ count($preview['plan']) }} loans · Scheduled principal TZS {{ $money(array_sum(array_column($preview['plan'], 'principal_minor'))) }} · Scheduled interest TZS {{ $money(array_sum(array_column($preview['plan'], 'interest_minor'))) }}</p>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Active loan migration preview">
                <table data-table-pages>
                    <thead><tr><th scope="col">Legacy loan</th><th scope="col">Member</th><th scope="col" class="money">Verified principal</th><th scope="col" class="money">Scheduled principal</th><th scope="col">Installments</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @foreach ($preview['plan'] as $row)
                            <tr>
                                <th scope="row">{{ $row['source_id'] }}</th>
                                <td>{{ $row['registration_no'] ?? '—' }}</td>
                                <td class="money">{{ $row['verified_minor'] === null ? '—' : $money($row['verified_minor']) }}</td>
                                <td class="money">{{ $money($row['principal_minor']) }}</td>
                                <td>
                                    <details>
                                        <summary>{{ $row['installment_count'] }} from {{ $row['first_due_date'] }}</summary>
                                        <ul>
                                            @foreach ($preview['schedules'][$row['source_id']] as $installment)
                                                <li>{{ $installment['due_date'] }} · Principal {{ $money($installment['principal_minor']) }} · Interest {{ $money($installment['interest_minor']) }}</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                </td>
                                <td>{{ $row['status'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($ready)
                <p>Verify the schedule against approved collection instructions and the opening loan receivable before applying. Applying creates repayment schedules. The opening journal already carries the receivable.</p>
                <form method="POST" action="{{ route('staff.active-loans.apply') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $preview['token'] }}">
                    <button class="primary">Apply migration</button>
                </form>
            @endif
        </section>
    @endif

    <section class="panel stacked-panel">
        <h2>Migration history</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Active loan migration history">
            <table>
                <thead><tr><th scope="col">Legacy loan</th><th scope="col">Member</th><th scope="col">Cutover</th><th scope="col" class="money">Principal (TZS)</th><th scope="col">Finance reference</th><th scope="col">Imported by</th></tr></thead>
                <tbody>
                    @forelse ($history as $row)
                        <tr>
                            <th scope="row"><a href="{{ route('staff.loan-applications.show', [$row->member_id, $row->id]) }}">{{ $row->source_id }}</a></th>
                            <td>{{ $row->registration_no }}</td><td>{{ $row->effective_on }}</td>
                            <td class="money">{{ $money($row->principal_minor) }}</td><td>{{ $row->review_reference }}</td><td>{{ $row->actor }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No active loans migrated.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $history->links() }}
    </section>
@endsection
