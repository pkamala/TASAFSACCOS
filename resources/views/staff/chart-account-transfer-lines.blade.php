<table>
    <thead>
        <tr><th scope="col">Account</th><th scope="col">Debit (TZS)</th><th scope="col">Credit (TZS)</th></tr>
    </thead>
    <tbody>
        @foreach ([[$original, -$signedMinor], [$replacement, $signedMinor]] as [$account, $amount])
            <tr>
                <th scope="row">{{ $account->code }} · {{ $account->name }} (ID {{ $account->id }})</th>
                <td>{{ $amount > 0 ? number_format($amount / 100, 2) : '—' }}</td>
                <td>{{ $amount < 0 ? number_format(-$amount / 100, 2) : '—' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
