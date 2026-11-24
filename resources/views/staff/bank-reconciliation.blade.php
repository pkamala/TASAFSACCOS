@extends('layout')
@section('title', 'Bank reconciliation')
@section('content')
    @php($money = fn ($minor) => number_format($minor / 100, 2))
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.cash-book.index') }}">Cash book</a>
        <a class="print-hide" href="{{ route('staff.ledger-periods.index', compact('month')) }}">Month-end checklist</a>
        <h1>Bank reconciliation</h1>
        <p>Statement ending {{ $end }} · TZS</p>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel stacked-panel print-hide">
        <form method="GET" action="{{ route('staff.bank-reconciliation.index') }}" class="fields search">
            <label for="bank-month">Statement month</label>
            <input id="bank-month" type="month" name="month" value="{{ $month }}" required>
            @if ($account)<input type="hidden" name="account" value="{{ $account->id }}">@endif
            <button class="primary">Show month</button>
        </form>
        <h2>Bank accounts</h2>
        @if ($statuses->isEmpty())
            <p>No bank accounts are configured for this month. To add one, open the account in the <a href="{{ route('staff.accounts.index') }}">chart of accounts</a> and enable cash book and bank reconciliation.</p>
        @else
            <div class="table-wrap" tabindex="0" role="region" aria-label="Bank reconciliation status">
                <table>
                    <thead><tr><th scope="col">Account</th><th scope="col">Status</th><th class="money" scope="col">Last reviewed difference</th></tr></thead>
                    <tbody>
                        @foreach ($statuses as $row)
                            <tr>
                                <th scope="row"><a href="{{ route('staff.bank-reconciliation.index', ['month' => $month, 'account' => $row['account']->id]) }}">{{ $row['account']->code }} · {{ $row['account']->name }}</a></th>
                                <td>{{ $row['status'] }}</td>
                                <td class="money">{{ $row['difference'] === null ? '—' : $money($row['difference']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($account && $reviewLocked)
        <p>The ledger month is closed. <a href="{{ route('staff.ledger-periods.index', compact('month')) }}">Reopen the month</a> to revise its bank reconciliation.</p>
    @endif
    @if ($account && ! $reviewLocked)
        <section class="panel stacked-panel print-hide">
            <h2>{{ $account->name }}</h2>
            <p>Compare the posted cash-book entries with the bank statement and mark each entry that cleared by {{ $end }}. Unticked entries carry as deposits in transit or outstanding payments. Post missing bank charges or receipts through the cash book before saving the review.</p>
            <form id="bank-reconciliation-form" method="POST" action="{{ route('staff.bank-reconciliation.store') }}" class="fields"
                  data-book-minor="{{ $bookMinor }}">
                @csrf
                <input type="hidden" name="month" value="{{ $month }}">
                <input type="hidden" name="account" value="{{ $account->id }}">
                <input type="hidden" name="basis" value="{{ $basis }}">
                <input type="hidden" name="cleared" value="[]">
                <label for="bank-statement-balance">Statement closing balance (TZS)</label>
                <input id="bank-statement-balance" name="statement" inputmode="decimal" maxlength="16"
                       value="{{ is_string(old('statement')) ? old('statement') : ($current ? number_format($current->statement_minor / 100, 2, '.', '') : '') }}" required>
                <label for="bank-statement-reference">Statement reference</label>
                <input id="bank-statement-reference" name="statement_reference" maxlength="100"
                       value="{{ is_string(old('statement_reference')) ? old('statement_reference') : ($current->statement_reference ?? '') }}" required>
                <label for="bank-review-note">Review or follow-up note</label>
                <textarea id="bank-review-note" name="review_note" maxlength="500" rows="3">{{ is_string(old('review_note')) ? old('review_note') : ($current->review_note ?? '') }}</textarea>
                <dl class="summary" aria-live="polite">
                    <div><dt>Cash-book balance</dt><dd>{{ $money($bookMinor) }}</dd></div>
                    <div><dt>Deposits in transit</dt><dd data-total="deposits">—</dd></div>
                    <div><dt>Outstanding payments</dt><dd data-total="payments">—</dd></div>
                    <div><dt>Adjusted bank balance</dt><dd data-total="adjusted">—</dd></div>
                    <div><dt>Difference from statement</dt><dd data-total="difference">—</dd></div>
                </dl>
                <label><input type="checkbox" data-unmatched-only> Show uncleared entries only</label>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Posted cash-book entries">
                    <table>
                        <thead><tr><th scope="col">Ledger date</th><th scope="col">Journal reference</th><th scope="col" class="money">Receipt</th><th scope="col" class="money">Payment</th><th scope="col">Cleared</th></tr></thead>
                        <tbody>
                            @forelse ($candidates as $key => $line)
                                <tr>
                                    <td>{{ $line->transaction_date }}</td><th scope="row">{{ $line->source_reference }}</th>
                                    <td class="money">{{ $line->signed_minor > 0 ? $money($line->signed_minor) : '—' }}</td>
                                    <td class="money">{{ $line->signed_minor < 0 ? $money(-$line->signed_minor) : '—' }}</td>
                                    <td><input type="checkbox" value="{{ $key }}" data-minor="{{ $line->signed_minor }}"
                                               aria-label="Cleared {{ $line->source_reference }} on {{ $line->transaction_date }}" @checked(isset($selected[$key]))></td>
                                </tr>
                            @empty
                                <tr><td colspan="5">No posted cash-book entries through the statement date.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <button class="primary" disabled data-save-review>Save review</button>
                <noscript><p>Enable JavaScript to match bank entries and save the review.</p></noscript>
            </form>
        </section>
    @endif
    @if ($account)
        <section class="panel stacked-panel print-register">
            <h2>{{ $account->name }} review history</h2>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Saved bank reviews">
                <table>
                    <thead><tr><th scope="col">Statement</th><th class="money" scope="col">Statement balance</th><th class="money" scope="col">Cash-book balance</th><th class="money" scope="col">Difference</th><th scope="col">Reviewed by</th><th scope="col">Outstanding entries</th></tr></thead>
                    <tbody>
                        @forelse ($history as $row)
                            @php($outstanding = json_decode($row->outstanding_lines))
                            <tr>
                                <th scope="row">{{ $row->statement_reference }} @if ($row->review_note)<small>{{ $row->review_note }}</small>@endif</th>
                                <td class="money">{{ $money($row->statement_minor) }}</td>
                                <td class="money">{{ $money($row->book_minor) }}</td>
                                <td class="money">{{ $money($row->book_minor - $row->outstanding_deposits_minor + $row->outstanding_payments_minor - $row->statement_minor) }}</td>
                                <td>{{ $row->actor }} · {{ \Carbon\CarbonImmutable::parse($row->reviewed_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</td>
                                <td>
                                    @if ($outstanding)
                                        <details><summary>{{ count($outstanding) }} {{ count($outstanding) === 1 ? 'entry' : 'entries' }}</summary>
                                            <ul>
                                                @foreach ($outstanding as $entry)
                                                    <li>{{ $entry->transaction_date }} · {{ $entry->source_reference }} · {{ $money($entry->signed_minor) }}</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @else
                                        None
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6">No reviewed reconciliation for this account and month.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @include('staff.pagination', ['page' => $history])
        </section>
    @endif
    @if ($account && ! $reviewLocked)
        <script>
            (() => {
                const form = document.getElementById('bank-reconciliation-form');
                const checks = [...form.querySelectorAll('input[data-minor]')];
                const unmatchedOnly = form.querySelector('[data-unmatched-only]');
                const format = value => {
                    const absolute = value < 0n ? -value : value;
                    return (value < 0n ? '-' : '') + (absolute / 100n).toLocaleString('en-US') + '.' + String(absolute % 100n).padStart(2, '0');
                };
                const update = () => {
                    let deposits = 0n;
                    let payments = 0n;
                    const cleared = [];
                    for (const check of checks) {
                        check.closest('tr').hidden = unmatchedOnly.checked && check.checked;
                        const amount = BigInt(check.dataset.minor);
                        if (check.checked) cleared.push(check.value);
                        else if (amount > 0n) deposits += amount;
                        else payments -= amount;
                    }
                    form.elements.cleared.value = JSON.stringify(cleared);
                    const adjusted = BigInt(form.dataset.bookMinor) - deposits + payments;
                    const statement = /^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/.exec(form.elements.statement.value.trim());
                    const difference = statement
                        ? adjusted - (statement[1] ? -1n : 1n) * (BigInt(statement[2]) * 100n + BigInt((statement[3] || '').padEnd(2, '0')))
                        : null;
                    for (const [key, value] of Object.entries({deposits, payments, adjusted, difference})) {
                        form.querySelector('[data-total="' + key + '"]').textContent = value === null ? '—' : format(value);
                    }
                };
                form.addEventListener('input', update);
                form.addEventListener('submit', update);
                update();
                form.querySelector('[data-save-review]').disabled = false;
            })();
        </script>
    @endif
@endsection
