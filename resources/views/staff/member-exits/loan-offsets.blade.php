<div class="table-wrap" tabindex="0" role="region" aria-label="Loan offsets">
    <table>
        <thead><tr><th scope="col">Loan</th><th scope="col" class="money">Applied (TZS)</th><th scope="col" class="money">Remaining at settlement (TZS)</th></tr></thead>
        <tbody>
            @foreach ($loans as $loan => $figures)
                <tr>
                    <th scope="row"><a href="{{ route('staff.loan-applications.show', [$memberId, $loan]) }}">Loan {{ $loan }}</a></th>
                    <td class="money">{{ number_format($figures['applied'] / 100, 2) }}</td>
                    <td class="money">{{ number_format(($figures['balance'] - $figures['applied']) / 100, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
