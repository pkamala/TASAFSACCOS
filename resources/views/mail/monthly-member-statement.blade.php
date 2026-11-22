<x-mail::message>
# Your statement for {{ $month }}

Dear member,

Here is your TASAF SACCOS account summary. Balances are as of {{ $asOfDate }}.

<x-mail::table>
| Item | Amount (TZS) |
| :--- | ---: |
| Outstanding loan balance | {{ number_format($loanBalance / 100, 2) }} |
| Contribution for the month | {{ number_format($contribution / 100, 2) }} |
| Savings balance | {{ number_format($savingsBalance / 100, 2) }} |
</x-mail::table>

If a figure differs from your records, please contact TASAF SACCOS.

TASAF SACCOS
</x-mail::message>
