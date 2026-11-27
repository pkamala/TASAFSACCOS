<?php

namespace App\Http\Controllers;

use App\ExitSettlementRates;
use App\MemberFunds;
use App\Models\User;
use App\RecordMemberFundTransaction;
use App\SavingsInterest;
use App\TablePage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use stdClass;

class MemberFundsController extends Controller
{
    public function index(int $member, MemberFunds $funds): View
    {
        $profile = DB::table('members')->find($member, ['id', 'first_name', 'last_name', 'registration_no', 'status']);
        abort_if($profile === null, 404);

        $history = DB::table('member_fund_transactions as transaction')
            ->join('users as recorder', 'recorder.id', '=', 'transaction.recorded_by')
            ->leftJoin('member_fund_journals as journal', 'journal.member_fund_transaction_id', '=', 'transaction.id')
            ->leftJoin('chart_accounts as receiving', 'receiving.id', '=', 'transaction.receiving_account_id')
            ->leftJoin('member_exits as exit', 'exit.settlement_batch_id', '=', 'transaction.loan_batch_id')
            ->where('transaction.member_id', $member)->orderByDesc('transaction.effective_on')->orderByDesc('transaction.id')
            ->tap(TablePage::search(['transaction.effective_on', 'transaction.kind', 'transaction.reference', 'transaction.note', 'recorder.name']))
            ->paginate(TablePage::size(), ['transaction.*', 'recorder.name as actor', 'journal.id as journal_id', 'exit.settlement_journal_id', 'receiving.code as receiving_code', 'receiving.name as receiving_name']);
        $payroll = DB::table('member_fund_transactions as transaction')
            ->join('loan_batches as batch', 'batch.id', '=', 'transaction.loan_batch_id')
            ->join('member_fund_journals as journal', 'journal.member_fund_transaction_id', '=', 'transaction.id')
            ->leftJoin('ledger_journal_reversals as reversal', 'reversal.member_fund_journal_id', '=', 'journal.id')
            ->where('transaction.member_id', $member)->where('batch.kind', 'repayment')->whereNull('reversal.id')
            ->selectRaw("coalesce(sum(transaction.signed_minor) FILTER (WHERE transaction.kind = 'savings_deposit'), 0) as savings,
                coalesce(sum(transaction.signed_minor) FILTER (WHERE transaction.kind = 'share_purchase'), 0) as shares")
            ->first();

        $receivingAccounts = $this->receivingAccounts()->orderBy('account.code')->get(['account.id', 'account.code', 'account.name']);

        return view('staff.member-funds', ['balances' => $funds->balances($member), ...compact('profile', 'history', 'payroll', 'receivingAccounts')]);
    }

    public function deposit(Request $request, int $member, MemberFunds $funds, RecordMemberFundTransaction $transactions): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:savings_deposit,share_purchase,member_deposit'],
            'amount' => $this->amountRules(),
            'receiving_account_id' => ['required', 'integer', 'min:1'],
            ...$this->receiptRules(),
        ]);

        DB::transaction(function () use ($request, $member, $data, $funds, $transactions): void {
            $profile = $this->lockMember($member);
            if ($profile->status !== 'active') {
                $this->invalid('The membership must be active.');
            }
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['chart-accounts']);
            if (! $this->receivingAccounts()->where('account.id', $data['receiving_account_id'])->exists()) {
                throw ValidationException::withMessages(['receiving_account_id' => 'Choose an active cashbook account.']);
            }

            $balance = $funds->balances($member);
            $this->checkOpeningDate($balance, $data['effective_on']);
            $transactions->record($request->user()->id, $member, $balance['opening']->id, $data, $data['kind'], $this->minor($data['amount']),
                receivingAccount: (int) $data['receiving_account_id']);
        }, 3);

        return back()->with('status', 'Confirmed contribution recorded. Prepare and post its journal to update the cashbook and general ledger.');
    }

    public function prepareDepositInterest(Request $request, int $member, MemberFunds $funds, RecordMemberFundTransaction $transactions): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m', 'after_or_equal:1900-01']]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month'], 'Africa/Dar_es_Salaam');
        if ($month->gte(CarbonImmutable::today('Africa/Dar_es_Salaam')->startOfMonth())) {
            throw ValidationException::withMessages(['month' => 'Choose a completed month.']);
        }

        $transaction = DB::transaction(function () use ($request, $member, $month, $funds, $transactions): int {
            $this->lockMember($member);
            $monthEnd = $month->endOfMonth()->toDateString();
            $reference = 'DEPOSIT-INTEREST-'.$month->format('Y-m');
            $credits = DB::table('member_fund_transactions')->where('member_id', $member)
                ->where('kind', 'deposit_interest')->where('reference', $reference);
            $existing = $credits->value('id');
            if ($existing !== null) {
                return $existing;
            }

            $principal = (int) DB::table('member_fund_transactions')->where('member_id', $member)
                ->where('kind', 'member_deposit')->where('effective_on', '<=', $monthEnd)->sum('signed_minor');
            $rate = match (true) {
                $principal < 500_000_000 => 1,
                $principal <= 5_000_000_000 => 2,
                $principal <= 10_000_000_000 => 3,
                default => 4,
            };
            $interest = intdiv($principal, 1200) * $rate + intdiv(($principal % 1200) * $rate + 600, 1200);
            if ($interest === 0) {
                $this->invalid('There is no payable deposit interest for this month.');
            }

            $balance = $funds->balances($member);
            $this->checkOpeningDate($balance, $monthEnd);
            $transactions->record($request->user()->id, $member, $balance['opening']->id, [
                'reference' => $reference,
                'effective_on' => $monthEnd,
                'note' => sprintf('Month-end principal TZS %s at %d%% per year, divided by 12.', number_format($principal / 100, 2), $rate),
            ], 'deposit_interest', $interest);

            return $credits->soleValue('id');
        }, 3);

        return redirect()->route('staff.member-fund-journals.show', $transaction);
    }

    public function prepareSavingsInterest(Request $request, int $member, MemberFunds $funds, RecordMemberFundTransaction $transactions, ExitSettlementRates $rates): RedirectResponse
    {
        $data = $request->validate(['savings_month' => ['required', 'date_format:Y-m', 'after_or_equal:1900-01']]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $data['savings_month'], 'Africa/Dar_es_Salaam');
        if ($month->gte(CarbonImmutable::today('Africa/Dar_es_Salaam')->startOfMonth())) {
            throw ValidationException::withMessages(['savings_month' => 'Choose a completed month.']);
        }

        $transaction = DB::transaction(function () use ($request, $member, $month, $funds, $transactions, $rates): int {
            $profile = $this->lockMember($member);
            $monthEnd = $month->endOfMonth();
            $reference = 'SAVINGS-INTEREST-'.$month->format('Y-m');
            $credits = DB::table('member_fund_transactions')->where('member_id', $member)
                ->where('kind', 'savings_interest')->where('reference', $reference);
            $existing = $credits->value('id');
            if ($existing !== null) {
                return $existing;
            }
            if (in_array($profile->status, ['exited', 'deceased'], true) && DB::table('member_exits')->where('member_id', $member)
                ->whereNotNull('settlement_journal_id')->exists()) {
                $this->invalid('Savings interest was accounted for in the posted exit settlement.');
            }

            $balance = $funds->balances($member);
            $this->checkOpeningDate($balance, $monthEnd->toDateString());
            $opening = $balance['opening'];
            $principal = $funds->savingsPrincipalAt($member, $opening->savings_minor, CarbonImmutable::parse($opening->as_of), $monthEnd);
            $currentRates = $rates->current();
            $interest = SavingsInterest::month($principal, $currentRates->savings_interest_basis_points);
            if ($interest === 0) {
                $this->invalid('There is no payable savings interest for this month.');
            }

            $transactions->record($request->user()->id, $member, $opening->id, [
                'reference' => $reference,
                'effective_on' => $monthEnd->toDateString(),
                'note' => sprintf('Month-end savings TZS %s at %s%% per year, divided by 12. Withholding at %s%%.',
                    number_format($principal / 100, 2), $currentRates->savings_interest_basis_points / 100, $currentRates->withholding_basis_points / 100),
            ], 'savings_interest', $interest, withholding: SavingsInterest::withholding($interest, $currentRates->withholding_basis_points));

            return $credits->soleValue('id');
        }, 3);

        return redirect()->route('staff.member-fund-journals.show', $transaction);
    }

    public function withdrawals(): View
    {
        $rows = DB::table('member_savings_withdrawals as withdrawal')->join('members', 'members.id', '=', 'withdrawal.member_id')
            ->tap(TablePage::memberStatus('members.status'))
            ->orderByDesc('withdrawal.id')
            ->tap(TablePage::search(['withdrawal.id', 'withdrawal.status', 'members.registration_no', 'members.first_name', 'members.last_name']))
            ->paginate(TablePage::size(), [
                'withdrawal.id', 'withdrawal.amount_minor', 'withdrawal.status', 'members.registration_no', 'members.first_name', 'members.last_name',
            ]);

        return view('staff.savings-withdrawals.index', compact('rows'));
    }

    public function showWithdrawal(int $withdrawal, MemberFunds $funds): View
    {
        $record = DB::table('member_savings_withdrawals')->find($withdrawal);
        abort_if($record === null, 404);
        $profile = DB::table('members')->find($record->member_id, ['id', 'first_name', 'last_name', 'registration_no']);
        $payment = DB::table('member_fund_transactions')->where('member_savings_withdrawal_id', $withdrawal)->first();
        $actors = DB::table('users')->whereIn('id', array_filter([$record->requested_by, $record->reviewed_by, $payment->recorded_by ?? null]))->pluck('name', 'id');

        return view('staff.savings-withdrawals.show', ['balances' => $funds->balances($profile->id), 'exceptions' => $this->withdrawalExceptions($record), ...compact('record', 'profile', 'payment', 'actors')]);
    }

    public function requestWithdrawal(Request $request, MemberFunds $funds): RedirectResponse
    {
        $member = $request->user()->member_id;
        abort_if($member === null, 403);
        $data = $request->validate(['amount' => $this->amountRules(), 'reason' => ['required', 'string', 'max:500']]);

        DB::transaction(function () use ($request, $funds, $member, $data): void {
            $profile = $this->lockMember($member);
            $balance = $funds->balances($member);
            $amount = $this->minor($data['amount']);
            if ($profile->status !== 'active' || DB::table('member_exits')->where('member_id', $member)->whereIn('status', ['requested', 'approved'])->exists()) {
                $this->invalid('Membership must be active without an open exit request.');
            }
            if ($balance['available'] === null || $balance['available'] < $amount) {
                $this->invalid('The requested amount exceeds available savings.');
            }
            if (DB::table('member_savings_withdrawals')->where('member_id', $member)->whereIn('status', ['requested', 'approved'])->exists()) {
                $this->invalid('Resolve your open savings withdrawal before requesting another.');
            }

            $lastPaidOn = DB::table('member_fund_transactions')
                ->where('member_id', $member)->where('kind', 'savings_withdrawal')->max('effective_on');

            DB::table('member_savings_withdrawals')->insert([
                'member_id' => $member, 'amount_minor' => $amount, 'status' => 'requested',
                'requested_by' => $request->user()->id, 'requested_at' => now(), 'reason' => $data['reason'],
                'savings_at_request_minor' => $balance['savings'], 'last_paid_withdrawal_on' => $lastPaidOn,
            ]);
        }, 3);

        return redirect()->route('member.account')->with('status', 'Savings withdrawal requested.');
    }

    public function review(Request $request, int $withdrawal, MemberFunds $funds): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,declined'],
            'review_note' => ['required', 'string', 'max:500'],
            'board_decision_reference' => ['nullable', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($request, $funds, $withdrawal, $data): void {
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['board-roles']);
            $officer = User::query()->findOrFail($request->user()->id);
            [$record, $profile] = $this->lockWithdrawal($withdrawal);
            abort_unless($officer->isBoardOfficer() && $officer->member_id !== $record->member_id && $officer->id !== $record->requested_by, 403);
            if ($record->status !== 'requested') {
                $this->invalid('Only a requested withdrawal can be reviewed.');
            }

            $decisionReference = null;
            if ($data['decision'] === 'approved') {
                if ($this->withdrawalExceptions($record) !== []) {
                    $decisionReference = $data['board_decision_reference'] ?? null;
                    if ($decisionReference === null) {
                        throw ValidationException::withMessages([
                            'board_decision_reference' => 'Enter the board decision reference to approve this exception.',
                        ]);
                    }
                }

                $balance = $funds->balances($record->member_id);
                if ($profile->status !== 'active' || $balance['available'] === null || $balance['available'] < $record->amount_minor) {
                    $this->invalid('The member must be active and have sufficient available savings.');
                }
            }

            DB::table('member_savings_withdrawals')->where('id', $withdrawal)->update([
                'status' => $data['decision'], 'reviewed_by' => $officer->id,
                'review_note' => $data['review_note'], 'reviewed_at' => now(),
                'board_decision_reference' => $decisionReference,
            ]);
        }, 3);

        return back()->with('status', 'Withdrawal decision recorded.');
    }

    public function pay(Request $request, int $withdrawal, MemberFunds $funds, RecordMemberFundTransaction $transactions): RedirectResponse
    {
        $data = $request->validate($this->receiptRules());

        DB::transaction(function () use ($request, $funds, $withdrawal, $data, $transactions): void {
            [$record, $profile] = $this->lockWithdrawal($withdrawal);
            if ($record->status !== 'approved' || $profile->status !== 'active') {
                $this->invalid('An approved withdrawal for an active member is required.');
            }
            $approvedOn = CarbonImmutable::parse($record->reviewed_at)->setTimezone('Africa/Dar_es_Salaam')->toDateString();
            $balance = $funds->balances($record->member_id);
            $this->checkOpeningDate($balance, $data['effective_on']);
            if ($data['effective_on'] < $approvedOn || ($balance['latest'] !== null && $data['effective_on'] < $balance['latest'])) {
                $this->invalid('The payout date must be on or after approval and the latest posted member transaction.');
            }
            if ($balance['available'] < 0) {
                $this->invalid('Available savings no longer cover all approved withdrawals.');
            }

            $transactions->record($request->user()->id, $record->member_id, $balance['opening']->id, $data, 'savings_withdrawal', -$record->amount_minor, $withdrawal);
            DB::table('member_savings_withdrawals')->where('id', $withdrawal)->update(['status' => 'paid']);
        }, 3);

        return back()->with('status', 'Confirmed savings payout recorded.');
    }

    private function withdrawalExceptions(stdClass $record): array
    {
        if ($record->savings_at_request_minor === null) {
            return ['The savings balance at the time of this request was not captured.'];
        }

        $exceptions = [];
        if ($record->amount_minor > intdiv($record->savings_at_request_minor, 4)) {
            $exceptions[] = 'The amount exceeds 25% of savings at the time of the request.';
        }
        if ($record->last_paid_withdrawal_on !== null) {
            $requestedOn = CarbonImmutable::parse($record->requested_at)->setTimezone('Africa/Dar_es_Salaam')->toDateString();
            $nextEligibleOn = CarbonImmutable::parse($record->last_paid_withdrawal_on, 'Africa/Dar_es_Salaam')
                ->addMonthsNoOverflow(12)->toDateString();
            if ($requestedOn < $nextEligibleOn) {
                $exceptions[] = 'The request was made within 12 months of the last paid withdrawal.';
            }
        }

        return $exceptions;
    }

    private function lockWithdrawal(int $id): array
    {
        $member = DB::table('member_savings_withdrawals')->where('id', $id)->value('member_id');
        abort_if($member === null, 404);
        $profile = $this->lockMember($member);
        $record = DB::table('member_savings_withdrawals')->lockForUpdate()->find($id);

        return [$record, $profile];
    }

    private function lockMember(int $member): stdClass
    {
        $profile = DB::table('members')->lockForUpdate()->find($member);
        abort_if($profile === null, 404);

        return $profile;
    }

    private function checkOpeningDate(array $balance, string $date): void
    {
        if ($balance['opening'] === null || $date < $balance['opening']->as_of) {
            $this->invalid('Record a verified opening balance dated on or before this transaction.');
        }
    }

    private function receivingAccounts(): Builder
    {
        return DB::table('chart_accounts as account')
            ->join('cash_book_accounts as cash', 'cash.chart_account_id', '=', 'account.id')
            ->where('account.is_ledger_account', true)->whereNull('account.retired_at');
    }

    private function amountRules(): array
    {
        return ['required', 'numeric', 'decimal:0,2', 'between:0.01,999999999999.99'];
    }

    private function receiptRules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:100'],
            'effective_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function minor(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['funds' => $message]);
    }
}
