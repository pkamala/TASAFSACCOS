<dl class="summary">
    <div><dt>Savings balance (TZS)</dt><dd>{{ number_format($balances['savings'] / 100, 2) }}</dd></div>
    <div><dt>Available savings (TZS)</dt><dd>{{ number_format($balances['available'] / 100, 2) }}</dd></div>
    <div><dt>Share value (TZS)</dt><dd>{{ number_format($balances['shares'] / 100, 2) }}</dd></div>
</dl>
@if ($balances['reserved'])
    <p class="note">TZS {{ number_format($balances['reserved'] / 100, 2) }} is reserved for approved withdrawals awaiting payout.</p>
@endif
