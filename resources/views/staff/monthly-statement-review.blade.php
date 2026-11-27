@extends('layout')
@section('title', 'Monthly statement review')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.statement-drafts.index', ['period' => $selected]) }}">Monthly statement drafts</a>
        <h1>Monthly statement review</h1>
        <a href="{{ route('staff.statement-review.sample', ['period' => $selected]) }}">Preview a sample email</a>
        <p>Review system-generated drafts or import an externally reviewed statement file.</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <div class="profile-grid">
        <section class="panel">
            <h2>Review system-generated drafts</h2>
            <form class="fields" method="POST" action="{{ route('staff.statement-review.preview-drafts') }}">
                @csrf
                <label for="draft-period">Statement month</label>
                <input id="draft-period" name="period" type="month" value="{{ is_string(old('period')) ? old('period') : $selected }}" required>
                <label for="draft-reference">Finance review reference</label>
                <textarea id="draft-reference" name="review_reference" maxlength="500" required>{{ is_string(old('review_reference')) ? old('review_reference') : '' }}</textarea>
                <button class="primary">Preview system drafts</button>
            </form>
            <details class="stacked-panel">
            <summary>Import a statement file reviewed outside the system</summary>
            <p>Confirm the figures and recipient addresses before queuing. The email in each row must match the member’s registered email.</p>
            <form class="fields" method="POST" action="{{ route('staff.statement-review.preview') }}" enctype="multipart/form-data">
                @csrf
                <label for="upload-period">Statement month</label>
                <input id="upload-period" name="period" type="month" value="{{ is_string(old('period')) ? old('period') : $selected }}" required>
                <label for="review_reference">Finance review reference</label>
                <textarea id="review_reference" name="review_reference" maxlength="500" required>{{ is_string(old('review_reference')) ? old('review_reference') : ($preview['review_reference'] ?? '') }}</textarea>
                <label for="statements">Reviewed statements (CSV)</label>
                <input id="statements" name="statements" type="file" accept=".csv,text/csv" required>
                <button class="primary">Preview statements</button>
            </form>
            </details>
        </section>
        <section class="panel">
            <h2>Delivery status</h2>
            <p><a href="{{ route('staff.mailing-accounts.index') }}#automatic-delivery">Manage sender and automatic delivery</a></p>
            <form class="fields" method="GET" action="{{ route('staff.statement-review.index') }}">
                <label for="period">Closed month</label>
                <input id="period" name="period" type="month" value="{{ $selected }}" required>
                <button class="primary">Show delivery status</button>
            </form>
            <p>{{ number_format($counts->get('pending', 0)) }} ready to send · {{ number_format($counts->get('recipient_mismatch', 0)) }} held for email mismatch · {{ number_format($counts->get('sending', 0)) }} sending · {{ number_format($counts->get('sent', 0)) }} sent · {{ number_format($counts->get('needs_review', 0)) }} need review</p>
            <p class="note">Scheduled delivery is {{ $automatic ? 'enabled' : 'disabled' }}. {{ $deliveryConfigured ? 'A supported mail transport is selected. Confirm delivery with your provider before sending to members.' : 'Select a supported mail transport before sending statements.' }}</p>
        </section>
    </div>

    @if ($preview)
        <section class="panel stacked-panel" id="preview">
            <h2>{{ ($preview['system_drafts'] ?? false) ? 'System draft preview' : 'Imported statement preview' }} · {{ $selected }}</h2>
            <p>{{ number_format(count($preview['plan'])) }} ready to queue · {{ number_format($preview['already_recorded']) }} already recorded</p>
            @if ($preview['plan'])
                <div class="table-wrap" tabindex="0" role="region" aria-label="Statements ready to queue">
                    <table data-table-pages>
                        <thead><tr><th scope="col">Member</th><th scope="col">Recipient</th><th scope="col" class="money">Loan (TZS)</th><th scope="col" class="money">Contribution (TZS)</th><th scope="col" class="money">Savings (TZS)</th></tr></thead>
                        <tbody>
                            @foreach ($preview['plan'] as $row)
                                <tr>
                                    <th scope="row">{{ $row['registration_no'] }} · {{ $row['name'] }}</th><td>{{ $row['recipient'] }}</td>
                                    @foreach (['loan_balance_minor', 'contribution_minor', 'savings_balance_minor'] as $key)
                                        <td class="money">{{ number_format($row[$key] / 100, 2) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <form method="POST" action="{{ route('staff.statement-review.queue') }}" class="stacked-panel">
                    @csrf
                    <input type="hidden" name="token" value="{{ $preview['token'] }}">
                    <button class="primary">Queue reviewed statements</button>
                </form>
            @endif
        </section>
    @endif

    <section class="panel stacked-panel">
        <h2>Recorded statements · {{ $selected }}</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Recorded statement delivery status">
            <table>
                <thead><tr><th scope="col">Member</th><th scope="col">Recipient</th><th scope="col">Delivery status</th><th scope="col">Queued by</th><th scope="col">Review reference</th><th scope="col">Sent at</th></tr></thead>
                <tbody>
                    @forelse ($queued as $row)
                        <tr>
                            <th scope="row">{{ $row->registration_no }} · {{ $row->first_name }} {{ $row->last_name }}</th><td>{{ $row->recipient }}</td>
                            <td>{{ $row->recipient_mismatch ? 'Held for email mismatch' : ucfirst(str_replace('_', ' ', $row->delivery_status)) }}</td><td>{{ $row->reviewer ?? '—' }}</td><td>{{ $row->review_reference ?? '—' }}</td>
                            <td>{{ $row->sent_at ? \Carbon\CarbonImmutable::parse($row->sent_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i').' EAT' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No statements recorded for this month.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $queued, 'memberStatusFilter' => true])
    </section>
@endsection
