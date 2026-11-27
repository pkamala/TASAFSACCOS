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
    @if ($record->status === 'pending')
        <section class="panel stacked-panel">
            <h2>Correct or approve a journal</h2>
            <p>Use the workbook date, mapped accounts, and amounts as shown above, or enter corrected values below. Approval queues a journal for posting.</p>
            @if ($record->workflow !== 'General journal')
                <p>Posting here updates the general ledger only. Use {{ strtolower($record->workflow) }} reconciliation for any changes to member or payroll records.</p>
            @endif
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
                    <label for="amount-{{ $loop->index }}">{{ $line['column'] }} · {{ $line['signed_minor'] < 0 ? 'Credit' : 'Debit' }} amount (TZS)</label>
                    <input id="amount-{{ $loop->index }}" name="amounts[]" type="text" inputmode="decimal"
                        value="{{ is_string(old('amounts.'.$loop->index)) ? old('amounts.'.$loop->index) : number_format(abs($line['signed_minor']) / 100, 2, '.', '') }}" required>
                @endforeach
                <label for="approval-note">Approval evidence</label>
                <textarea id="approval-note" name="review_note" maxlength="500" required>{{ old('decision') === 'approve' ? old('review_note') : '' }}</textarea>
                @if ($record->workflow !== 'General journal' || $matches)
                    <label>
                        <input type="checkbox" name="ledger_acknowledged" value="1" @checked(old('ledger_acknowledged')) required>
                        I checked for an existing posting and approve this entry for the general ledger only.
                    </label>
                @endif
                <div class="actions">
                    <button class="primary" name="values" value="workbook" formnovalidate>Approve workbook values</button>
                    <button class="primary" name="values" value="corrected">Approve corrected values</button>
                </div>
            </form>
        </section>
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
                <form method="POST" action="{{ route('staff.cash-book-review.post-entry', $record->id) }}">
                    @csrf
                    <button class="primary">Post this journal</button>
                </form>
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
    @if ($record->status === 'pending')
        <section class="panel stacked-panel">
            <h2>Follow-up</h2>
            <form method="POST" class="fields" action="{{ route('staff.cash-book-review.review', $record->id) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="decision" value="hold">
                <label for="follow-up-note">Correction or evidence needed</label>
                <textarea id="follow-up-note" name="review_note" maxlength="500" required>{{ old('decision') === 'hold' ? old('review_note') : $record->review_note }}</textarea>
                <button class="primary">Save follow-up</button>
            </form>
        </section>
    @endif
@endsection
