<div class="table-wrap" tabindex="0" role="region" aria-label="General journal lines">
    <table data-table-pages>
        <thead><tr><th scope="col">Account</th><th scope="col" class="money">Debit (TZS)</th><th scope="col" class="money">Credit (TZS)</th></tr></thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <th scope="row">{{ $line->code }} · {{ $line->name }} (ID {{ $line->chart_account_id }})</th>
                    <td class="money">{{ $line->signed_minor > 0 ? number_format($line->signed_minor / 100, 2) : '—' }}</td>
                    <td class="money">{{ $line->signed_minor < 0 ? number_format(-$line->signed_minor / 100, 2) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot><tr><th scope="row">Totals</th><td class="money">{{ number_format($lines->where('signed_minor', '>', 0)->sum('signed_minor') / 100, 2) }}</td><td class="money">{{ number_format(-$lines->where('signed_minor', '<', 0)->sum('signed_minor') / 100, 2) }}</td></tr></tfoot>
    </table>
</div>
