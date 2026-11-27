@extends('layout')
@section('title', 'Review cashbook entry')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.cash-book-review.index', ['month' => substr($record->month, 0, 7)]) }}">Cashbook review</a>
        <h1>{{ $record->source_key }}</h1>
        <p>{{ $record->source_file }} · Row {{ $record->source_row }} · {{ ucfirst($record->status) }}</p>
        <p>{{ $record->payee }} @if ($record->description) · {{ $record->description }} @endif</p>
        <p>Source date {{ $record->raw_date ?: 'missing' }} · Proposed date {{ $record->transaction_date ?? 'pending' }} @if ($record->payment_reference) · Reference {{ $record->payment_reference }} @endif</p>
    </div>
    @include('form-errors')
    @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
    <section class="panel stacked-panel">
        <h2>Workbook lines</h2>
        @if ($record->issues)<p>{{ $record->issues }}</p>@endif
        @if ($record->general_journal_id)<p><a href="{{ route('staff.general-journals.show', $record->general_journal_id) }}">Open posted journal</a></p>@endif
        @if ($record->matched_journal)<p>Matched journal {{ $record->matched_journal }}</p>@endif
        @if ($record->review_note)<p>Review note · {{ $record->review_note }}</p>@endif
        <div class="table-wrap" tabindex="0" role="region" aria-label="Workbook journal lines">
            <table>
                <thead><tr><th scope="col">Column</th><th scope="col">Source account</th><th scope="col">Proposed code</th><th scope="col">Debit (TZS)</th><th scope="col">Credit (TZS)</th></tr></thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <th scope="row">{{ $line['column'] }}</th><td>{{ $line['label'] }}</td>
                            <td>{{ $line['code'] ?? 'Needs mapping' }}</td>
                            <td class="money">{{ $line['signed_minor'] > 0 ? number_format($line['signed_minor'] / 100, 2) : '—' }}</td>
                            <td class="money">{{ $line['signed_minor'] < 0 ? number_format(-$line['signed_minor'] / 100, 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @if ($record->status === 'pending' && $matches)
        <section class="panel stacked-panel">
            <h2>Match an existing journal</h2>
            <p>Confirm the source evidence and bank account before linking a candidate.</p>
            <form method="POST" class="fields" action="{{ route('staff.cash-book-review.review', $record->id) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="decision" value="match">
                <label for="journal-match">Existing journal</label>
                <select id="journal-match" name="match" required>
                    <option value="">Select a journal</option>
                    @foreach ($matches as $match)
                        <option value="{{ $match['key'] }}" @selected(old('match') === $match['key'])>{{ $match['reference'] }} · {{ $match['kind'] }} · {{ $match['member_number'] }} · Posted bank {{ $match['existing_bank_code'] }} · Workbook bank {{ $match['workbook_bank_code'] }} · {{ $match['evidence'] }}</option>
                    @endforeach
                </select>
                <label for="match-note">Match evidence</label>
                <textarea id="match-note" name="review_note" maxlength="500" required>{{ old('decision') === 'match' ? old('review_note') : '' }}</textarea>
                <button class="primary">Confirm existing match</button>
            </form>
        </section>
    @endif
    @if ($record->status === 'pending' && $record->workflow === 'General journal')
        <section class="panel stacked-panel">
            <h2>Approve a new journal</h2>
            <form method="POST" class="fields" action="{{ route('staff.cash-book-review.review', $record->id) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="decision" value="approve">
                <label for="transaction-date">Transaction date</label>
                <input type="date" name="transaction_date" id="transaction-date" value="{{ old('transaction_date', $record->transaction_date) }}" required>
                @foreach ($lines as $line)
                    <label for="account-{{ $loop->index }}">{{ $line['column'] }} · {{ $line['label'] }}</label>
                    <select id="account-{{ $loop->index }}" name="accounts[]" required>
                        <option value="">Select an account</option>
                        @foreach ($accounts as $account)
                            @continue($line['bank_code'] !== null && $line['bank_code'] !== $account->code)
                            <option value="{{ $account->id }}" @selected(old('accounts.'.$loop->parent->index, $line['code'] === $account->code ? $account->id : null) == $account->id)>{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                @endforeach
                <label for="approval-note">Approval evidence</label>
                <textarea id="approval-note" name="review_note" maxlength="500" required>{{ old('decision') === 'approve' ? old('review_note') : '' }}</textarea>
                <button class="primary">Approve new journal</button>
            </form>
        </section>
    @elseif ($record->status === 'pending' && $record->workflow !== 'General journal')
        <p>Review this entry through {{ strtolower($record->workflow) }} reconciliation.</p>
    @elseif (in_array($record->status, ['matched', 'approved'], true))
        <section class="panel stacked-panel">
            @if ($approved)
                <h2>Approved posting · {{ $record->approved_date }}</h2>
                <ul>
                    @foreach ($approved as $line)
                        @php
                            $approvedAccount = $accounts->firstWhere('id', $line['chart_account_id']);
                        @endphp
                        <li>{{ $approvedAccount?->code }} {{ $approvedAccount?->name ?? 'Unavailable account' }} · {{ $line['signed_minor'] > 0 ? 'Debit' : 'Credit' }} {{ number_format(abs($line['signed_minor']) / 100, 2) }} TZS</li>
                    @endforeach
                </ul>
            @endif
            <form method="POST" class="fields" action="{{ route('staff.cash-book-review.review', $record->id) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="decision" value="reopen">
                <label for="reopen-note">Reason for reopening</label>
                <textarea id="reopen-note" name="review_note" maxlength="500" required>{{ old('decision') === 'reopen' ? old('review_note') : '' }}</textarea>
                <button class="primary">Reopen review</button>
            </form>
        </section>
    @endif
@endsection
