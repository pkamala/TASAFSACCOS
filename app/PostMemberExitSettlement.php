<?php

namespace App;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class PostMemberExitSettlement
{
    public const Accounts = [
        'savings' => 'Member savings liability',
        'shares' => 'Share capital',
        'interest_expense' => 'Savings interest expense',
        'fee' => 'Exit fee income',
        'withholding' => 'Withholding payable',
        'loan_principal' => 'Loan principal receivable',
        'loan_interest' => 'Loan interest income',
        'payout' => 'Exit payout payable',
    ];

    public function __construct(
        private MemberExitSettlement $settlements,
        private MemberFunds $funds,
        private LoanTopUp $loans,
        private RecordLoanRepayment $repayments,
        private RecordMemberFundTransaction $transactions,
        private LedgerPeriods $periods,
        private ChartPostingAccounts $accounts,
        private JournalPosting $journals,
    ) {}

    public function plan(int $member, CarbonImmutable $date, bool $lock = false): array
    {
        $this->requireEffectiveDate($member, $date);
        $active = DB::table('loan_applications')->where('member_id', $member)->where('status', 'disbursed')->orderBy('id');
        $loanIds = ($lock ? $active->lockForUpdate() : $active)->pluck('id');
        $amounts = $this->settlements->draft($member, $date);
        if ($amounts === null || min($amounts['savings'], $amounts['shares']) < 0) {
            $this->invalid('Verify the opening balances and resolve any negative savings or shares before posting.');
        }
        $loans = [];
        foreach ($loanIds as $loan) {
            $quote = $this->loans->quote($loan, $date->endOfMonth()->toDateString());
            if ($quote['total'] > 0) {
                $loans[$loan] = ['balance' => $quote['total'], 'applied' => 0, 'allocations' => [], 'due' => $quote['allocations']];
            }
        }
        $available = $amounts['savings'] + $amounts['shares'] - $amounts['fee'] + $amounts['interest']['net'];
        $allocated = ['interest' => 0, 'principal' => 0];
        foreach (array_keys($allocated) as $kind) {
            foreach ($loans as &$loan) {
                foreach ($loan['due'] as $number => $due) {
                    $payment = min($available, $due[$kind]);
                    if ($payment > 0) {
                        $loan['allocations'][$number][$kind] = $payment;
                        $loan['applied'] += $payment;
                        $allocated[$kind] += $payment;
                        $available -= $payment;
                    }
                }
            }
            unset($loan);
        }
        foreach ($loans as &$loan) {
            unset($loan['due']);
        }
        unset($loan);

        $movements = array_filter([
            'savings' => $amounts['savings'],
            'shares' => $amounts['shares'],
            'interest_expense' => $amounts['interest']['gross'],
            'fee' => -$amounts['fee'],
            'withholding' => -$amounts['interest']['withholding'],
            'loan_principal' => -$allocated['principal'],
            'loan_interest' => -$allocated['interest'],
            'payout' => -$available,
        ]);

        return [
            'calculated_on' => $date->toDateString(), 'amounts' => $amounts, 'loans' => $loans,
            'movements' => $movements, 'payable' => $available,
            'outstanding' => array_sum(array_column($loans, 'balance')) - array_sum($allocated),
        ];
    }

    public function fingerprint(array $plan): string
    {
        return hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
    }

    public function post(int $exit, int $actor, CarbonImmutable $date, string $fingerprint, array $accountIds, ?string $legacyClearance): void
    {
        DB::transaction(function () use ($exit, $actor, $date, $fingerprint, $accountIds, $legacyClearance): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['payroll-schedule']);
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['board-roles']);
            $initial = DB::table('member_exits')->find($exit);
            abort_if($initial === null, 404);
            $member = DB::table('members')->lockForUpdate()->find($initial->member_id);
            $record = DB::table('member_exits')->lockForUpdate()->find($exit);
            $snapshot = json_decode($record->voucher_snapshot ?? 'null', true, flags: JSON_THROW_ON_ERROR);
            if ($record->status === 'finalized' && isset($snapshot['movements'])) {
                return;
            }
            if ($record->status !== 'approved' || ! in_array($member->status, ['active', 'inactive', 'deceased'], true)) {
                $this->invalid('An approved exit for an unsettled membership is required.');
            }
            if ($member->legacy_member_id !== null && $legacyClearance === null) {
                $this->invalid('Record the legacy balance clearance reference.');
            }
            $this->requireReady($member->id);
            $plan = $this->plan($member->id, $date, lock: true);
            if (! hash_equals($this->fingerprint($plan), $fingerprint)) {
                $this->invalid('The settlement changed. Review the current figures and submit again.');
            }
            $this->periods->requireOpenDates([$plan['calculated_on']], 'exit');
            $lines = $this->lines($plan['movements'], $accountIds);
            $reference = 'EXIT-'.$exit;
            $journal = $lines === [] ? null : $this->journals->writeGeneral($actor, (string) Str::uuid(), [
                'transaction_date' => $plan['calculated_on'], 'category' => 'general',
                'source_reference' => $reference, 'description' => "Membership exit settlement for {$member->registration_no}",
            ], $lines);
            $batch = $journal === null ? null : $this->recordMovements($member, $plan, $actor, $reference);

            DB::table('member_exits')->where('id', $exit)->update([
                'status' => 'finalized', 'effective_on' => $plan['calculated_on'], 'finalized_by' => $actor, 'finalized_at' => now(),
                'settlement_reference' => $reference, 'legacy_clearance_reference' => $legacyClearance,
                'settlement_journal_id' => $journal, 'settlement_batch_id' => $batch,
                'voucher_snapshot' => json_encode($plan, JSON_THROW_ON_ERROR),
            ]);
            $this->settlements->deactivateMember($member->id, $exit, $actor);
        }, 3);
    }

    private function requireEffectiveDate(int $member, CarbonImmutable $date): void
    {
        $effectiveOn = $date->toDateString();
        $joiningDate = DB::table('members')->where('id', $member)->value('joining_date');
        if ($joiningDate !== null && $effectiveOn < $joiningDate) {
            $this->invalid('The effective date cannot precede the joining date.');
        }

        if (DB::table('member_verified_balances')->where('member_id', $member)->whereNull('superseded_by_id')->where('as_of', '>', $effectiveOn)->exists()
            || DB::table('member_fund_transactions')->where('member_id', $member)->where('effective_on', '>', $effectiveOn)->exists()
            || DB::table('loan_disbursements as transfer')->join('loan_applications as loan', 'loan.id', '=', 'transfer.loan_application_id')
                ->where('loan.member_id', $member)->where('transfer.transferred_on', '>', $effectiveOn)->exists()
            || DB::table('loan_repayment_receipts as receipt')->join('loan_applications as loan', 'loan.id', '=', 'receipt.loan_application_id')
                ->where('loan.member_id', $member)->where('receipt.received_on', '>', $effectiveOn)->exists()) {
            $this->invalid('Reconcile member balances, fund movements, and loan activity dated after the effective date before posting.');
        }
    }

    private function requireReady(int $member): void
    {
        if (DB::table('loan_applications')->where('member_id', $member)->whereIn('status', ['submitted', 'approved'])->exists()
            || DB::table('member_savings_withdrawals')->where('member_id', $member)->whereIn('status', ['requested', 'approved'])->exists()) {
            $this->invalid('Resolve open loan applications and withdrawal requests before settling the exit.');
        }
        $activeLoans = DB::table('loan_applications')->where('member_id', $member)->where('status', 'disbursed')->pluck('id');
        $batches = DB::table('loan_batches')->where('kind', 'repayment')->whereIn('status', ['prepared', 'approved'])->pluck('items');
        foreach ($batches as $items) {
            foreach (json_decode($items, true, flags: JSON_THROW_ON_ERROR) as $item) {
                if ($activeLoans->contains($item['loan_id'] ?? null)) {
                    $this->invalid('Resolve the member’s open payroll list before settling the exit.');
                }
            }
        }
        if (DB::table('member_fund_transactions as fund')->where('fund.member_id', $member)->where('fund.kind', 'savings_interest')
            ->whereNotExists(fn ($journal) => $journal->selectRaw('1')->from('member_fund_journals as posted')
                ->whereColumn('posted.member_fund_transaction_id', 'fund.id'))->exists()) {
            $this->invalid('Resolve unposted savings interest drafts before settling the exit.');
        }
    }

    private function lines(array $movements, array $accountIds): array
    {
        $lines = [];
        foreach ($movements as $kind => $amount) {
            if (! isset($accountIds[$kind])) {
                $this->invalid('Select a ledger account for each settlement line.');
            }
            $lines[] = ['chart_account_id' => (int) $accountIds[$kind], 'signed_minor' => $amount];
        }
        if (array_sum($movements) !== 0 || ! $this->accounts->available(array_column($lines, 'chart_account_id'))) {
            $this->invalid('Use distinct active ledger accounts for the balanced settlement lines.');
        }

        return $lines;
    }

    private function recordMovements(stdClass $member, array $plan, int $actor, string $reference): int
    {
        $batch = DB::table('loan_batches')->insertGetId([
            'kind' => 'exit_settlement', 'items' => '[]', 'status' => 'confirmed',
            'effective_date' => $plan['calculated_on'], 'prepared_by' => $actor, 'prepared_at' => now(),
            'confirmed_by' => $actor, 'confirmed_at' => now(),
        ]);
        $opening = $this->funds->balances($member->id)['opening'];
        foreach (['savings', 'shares'] as $kind) {
            if ($plan['amounts'][$kind] > 0) {
                $this->transactions->record($actor, $member->id, $opening->id, [
                    'reference' => $reference, 'effective_on' => $plan['calculated_on'],
                ], 'exit_'.$kind, -$plan['amounts'][$kind], batch: $batch);
            }
        }
        $money = static fn (int $minor): string => number_format($minor / 100, 2, '.', '');
        foreach ($plan['loans'] as $id => $loan) {
            if ($loan['applied'] > 0) {
                $this->repayments->record([
                    'receipt_reference' => $reference, 'received_on' => $plan['calculated_on'],
                    'amount' => $money($loan['applied']),
                    'allocations' => array_map(fn (array $allocation): array => array_map($money, $allocation), $loan['allocations']),
                ], $actor, $member->id, $id, $batch);
                if ($loan['applied'] === $loan['balance']) {
                    DB::table('loan_applications')->where('id', $id)->update(['status' => 'repaid']);
                }
            }
        }

        return $batch;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['exit' => $message]);
    }
}
