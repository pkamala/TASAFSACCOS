<?php

namespace App\Http\Controllers;

use App\ChartPostingAccounts;
use App\LedgerPeriods;
use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use stdClass;

class MemberFundJournalController extends Controller
{
    public function __construct(private LedgerPeriods $periods, private ChartPostingAccounts $chart) {}

    public function index(): View
    {
        $journals = DB::table('member_fund_journals as journal')
            ->join('member_fund_transactions as transaction', 'transaction.id', '=', 'journal.member_fund_transaction_id')
            ->join('members as member', 'member.id', '=', 'transaction.member_id')
            ->join('users as actor', 'actor.id', '=', 'journal.posted_by')
            ->orderByDesc('journal.id')->simplePaginate(TablePage::size(), [
                'journal.id', 'journal.member_fund_transaction_id', 'journal.transaction_date', 'journal.source_reference',
                'journal.amount_minor', 'transaction.kind', 'member.registration_no', 'actor.name as actor',
            ]);

        return view('staff.member-fund-journals.index', compact('journals'));
    }

    public function show(Request $request, int $transaction): View
    {
        $record = $this->source($transaction);
        $accounts = DB::table('chart_accounts')->orderBy('code')->orderBy('id')
            ->get(['id', 'code', 'name', 'legacy_id', 'is_ledger_account', 'retired_at'])->keyBy('id');
        $posting = DB::table('member_fund_journals as journal')->where('journal.member_fund_transaction_id', $transaction)
            ->join('users as actor', 'actor.id', '=', 'journal.posted_by')
            ->first(['journal.*', 'actor.name as actor']);
        $reversalId = $posting ? DB::table('ledger_journal_reversals')->where('member_fund_journal_id', $posting->id)->value('id') : null;
        $preview = $request->session()->get('member_fund_journal_preview');
        if (($preview['transaction'] ?? null) !== $transaction || ($preview['actor'] ?? null) !== $request->user()->id || $posting) {
            $preview = null;
        }

        return view('staff.member-fund-journals.show', compact('reversalId', 'record', 'accounts', 'posting', 'preview'));
    }

    public function preview(Request $request, int $transaction): RedirectResponse
    {
        $request->session()->forget('member_fund_journal_preview');
        $record = $this->source($transaction);
        if (DB::table('member_fund_journals')->where('member_fund_transaction_id', $transaction)->exists()) {
            $this->invalid('This member transaction has already been posted.');
        }

        $input = $request->validate([
            'cash_account' => ['required', 'integer', 'min:1'],
            'member_account' => ['required', 'integer', 'min:1', 'different:cash_account'],
        ]);
        $journal = $this->journal($record, (int) $input['cash_account'], (int) $input['member_account']);
        $request->session()->put('member_fund_journal_preview', [
            'transaction' => $transaction, 'actor' => $request->user()->id, 'token' => Str::random(40),
            'cash' => (int) $input['cash_account'], 'fund' => (int) $input['member_account'], 'journal' => $journal,
        ]);

        return redirect()->route('staff.member-fund-journals.show', $transaction);
    }

    public function post(Request $request, int $transaction): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:40']]);
        $preview = $request->session()->get('member_fund_journal_preview');
        $actor = $request->user()->id;
        if (! $preview || $preview['actor'] !== $actor || $preview['transaction'] !== $transaction || ! hash_equals($preview['token'], $data['token'])) {
            $this->invalid('Preview the member fund journal before posting.');
        }

        DB::transaction(function () use ($preview, $actor, $transaction): void {
            $record = DB::table('member_fund_transactions')->lockForUpdate()->find($transaction);
            if (! $record || DB::table('member_fund_journals')->where('member_fund_transaction_id', $transaction)->exists()) {
                $this->invalid('Only an unposted member transaction can be posted.');
            }
            $journal = $this->journal($record, $preview['cash'], $preview['fund']);
            if ($journal !== $preview['journal']) {
                $this->invalid('The transaction changed after preview. Preview the journal again.');
            }

            $this->periods->requireOpenDates([$journal['transaction_date']], 'journal');

            DB::table('member_fund_journals')->insert([
                ...$journal, 'member_fund_transaction_id' => $transaction, 'posted_by' => $actor, 'posted_at' => now(),
            ]);
        }, 3);

        $request->session()->forget('member_fund_journal_preview');

        return redirect()->route('staff.member-fund-journals.show', $transaction)->with('status', 'Member fund journal posted.');
    }

    private function source(int $id): stdClass
    {
        $record = DB::table('member_fund_transactions as transaction')
            ->join('members as member', 'member.id', '=', 'transaction.member_id')
            ->where('transaction.id', $id)
            ->first(['transaction.*', 'member.registration_no', 'member.first_name', 'member.last_name']);
        abort_if($record === null, 404);

        return $record;
    }

    private function journal(stdClass $record, int $cash, int $fund): array
    {
        if ($cash === $fund || ! $this->chart->available([$cash, $fund])) {
            $this->invalid('Select two distinct posting accounts from the chart of accounts.');
        }

        $withdrawal = $record->kind === 'savings_withdrawal';

        return [
            'debit_account_id' => $withdrawal ? $fund : $cash,
            'credit_account_id' => $withdrawal ? $cash : $fund,
            'amount_minor' => abs($record->signed_minor),
            'transaction_date' => $record->effective_on,
            'source_reference' => $record->reference,
        ];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['journal' => $message]);
    }
}
