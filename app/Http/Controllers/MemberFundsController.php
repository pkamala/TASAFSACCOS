<?php

namespace App\Http\Controllers;

use App\MemberFunds;
use App\Models\User;
use App\RecordMemberFundTransaction;
use App\TablePage;
use Carbon\CarbonImmutable;
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
            ->where('transaction.member_id', $member)->orderByDesc('transaction.effective_on')->orderByDesc('transaction.id')
            ->simplePaginate(TablePage::size(), ['transaction.*', 'recorder.name as actor', 'journal.id as journal_id']);

        return view('staff.member-funds', ['balances' => $funds->balances($member), ...compact('profile', 'history')]);
    }

    public function deposit(Request $request, int $member, MemberFunds $funds, RecordMemberFundTransaction $transactions): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:savings_deposit,share_purchase'],
            'amount' => $this->amountRules(),
            ...$this->receiptRules(),
        ]);

        DB::transaction(function () use ($request, $member, $data, $funds, $transactions): void {
            $profile = $this->lockMember($member);
            if ($profile->status !== 'active') {
                $this->invalid('The membership must be active.');
            }
            $balance = $funds->balances($member);
            $this->checkOpeningDate($balance, $data['effective_on']);
            $transactions->record($request->user()->id, $member, $balance['opening']->id, $data, $data['kind'], $this->minor($data['amount']));
        }, 3);

        return back()->with('status', 'Confirmed contribution recorded.');
    }

    public function withdrawals(): View
    {
        $rows = DB::table('member_savings_withdrawals as withdrawal')->join('members', 'members.id', '=', 'withdrawal.member_id')
            ->orderByDesc('withdrawal.id')->simplePaginate(TablePage::size(), [
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

        return view('staff.savings-withdrawals.show', ['balances' => $funds->balances($profile->id), ...compact('record', 'profile', 'payment', 'actors')]);
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

            DB::table('member_savings_withdrawals')->insert([
                'member_id' => $member, 'amount_minor' => $amount, 'status' => 'requested',
                'requested_by' => $request->user()->id, 'requested_at' => now(), 'reason' => $data['reason'],
            ]);
        }, 3);

        return redirect()->route('member.account')->with('status', 'Savings withdrawal requested.');
    }

    public function review(Request $request, int $withdrawal, MemberFunds $funds): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,declined'],
            'review_note' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $funds, $withdrawal, $data): void {
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['board-roles']);
            $officer = User::query()->findOrFail($request->user()->id);
            [$record, $profile] = $this->lockWithdrawal($withdrawal);
            abort_unless($officer->isBoardOfficer() && $officer->member_id !== $record->member_id && $officer->id !== $record->requested_by, 403);
            if ($record->status !== 'requested') {
                $this->invalid('Only a requested withdrawal can be reviewed.');
            }

            if ($data['decision'] === 'approved') {
                $balance = $funds->balances($record->member_id);
                if ($profile->status !== 'active' || $balance['available'] === null || $balance['available'] < $record->amount_minor) {
                    $this->invalid('The member must be active and have sufficient available savings.');
                }
            }

            DB::table('member_savings_withdrawals')->where('id', $withdrawal)->update([
                'status' => $data['decision'], 'reviewed_by' => $officer->id,
                'review_note' => $data['review_note'], 'reviewed_at' => now(),
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
