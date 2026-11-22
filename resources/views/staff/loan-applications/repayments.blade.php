<section class="panel stacked-panel" aria-labelledby="repayments-heading">
    <h2 id="repayments-heading">Repayments</h2>
    <dl class="summary">
        <div><dt>Principal remaining (TZS)</dt><dd>{{ number_format(($installments->sum('principal_minor') - $paid->sum('principal_minor')) / 100, 2) }}</dd></div>
        <div><dt>Scheduled interest remaining (TZS)</dt><dd>{{ number_format(($installments->sum('interest_minor') - $paid->sum('interest_minor')) / 100, 2) }}</dd></div>
    </dl>
    @if ($record->status === 'disbursed')
        <p>Prepare and confirm repayments through the <a href="{{ route('staff.loan-batches.index') }}">monthly repayment lists</a>.</p>
    @endif
    <h3>Receipt history</h3>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Loan receipt history">
        <table>
            <thead><tr><th scope="col">Receipt reference</th><th scope="col">Payment date</th><th scope="col" class="money">Amount (TZS)</th><th scope="col">Recorded by</th><th scope="col">Allocations (TZS)</th></tr></thead>
            <tbody>
                @forelse ($receipts as $receipt)
                    <tr><th scope="row">{{ $receipt->receipt_reference }}</th><td>{{ $receipt->received_on }}</td><td class="money">{{ number_format($receipt->amount_minor / 100, 2) }}</td><td>{{ $receipt->recorder }} · {{ \Carbon\CarbonImmutable::parse($receipt->recorded_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</td>
                        <td><details><summary>View allocations</summary>
                            <ul>
                                @foreach ($receiptAllocations->get($receipt->id, []) as $allocation)
                                    <li>Month {{ $allocation->installment_number }} · Principal {{ number_format($allocation->principal_minor / 100, 2) }} · Interest {{ number_format($allocation->interest_minor / 100, 2) }}</li>
                                @endforeach
                            </ul>
                        </details></td>
                    </tr>
                @empty
                    <tr><td colspan="5">No receipts recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('staff.pagination', ['page' => $receipts])
</section>
