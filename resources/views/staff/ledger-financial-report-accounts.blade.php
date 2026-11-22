<div class="table-wrap stacked-panel" tabindex="0" role="region" aria-label="Financial report accounts">
    <table data-table-pages>
        <thead><tr><th scope="col">Category</th><th scope="col">Account</th><th scope="col" class="money">Amount (TZS)</th></tr></thead>
        <tbody>
            @foreach ($sections as [$label, $accounts, $direction])
                @foreach ($accounts->filter(fn ($row) => (int) $row->$amountColumn !== 0) as $row)
                    <tr>
                        <td>{{ $label }}</td>
                        <th scope="row"><a href="{{ route('staff.loan-ledger.account', ['account' => $row->id, 'from' => $from, 'to' => $to]) }}">{{ $row->code }} · {{ $row->name }}</a><small>{{ $row->legacy_id ? 'Legacy ID '.$row->legacy_id : 'Account ID '.$row->id }}</small></th>
                        <td class="money">{{ number_format($direction * $row->$amountColumn / 100, 2) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>
