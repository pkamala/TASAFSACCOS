<?php

namespace App\Http\Controllers;

use App\JournalPosting;
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
    public function __construct(private LedgerPeriods $periods, private JournalPosting $posting) {}

    public function index(): View
    {
        $journals = DB::table('member_fund_journals as journal')
            ->join('member_fund_transactions as transaction', 'transaction.id', '=', 'journal.member_fund_transaction_id')
            ->join('members as member', 'member.id', '=', 'transaction.member_id')
            ->join('users as actor', 'actor.id', '=', 'journal.posted_by')
            ->orderByDesc('journal.id')
            ->tap(TablePage::memberStatus('member.status'))
            ->tap(TablePage::search(['journal.id', 'journal.transaction_date', 'journal.source_reference', 'transaction.kind', 'member.registration_no', 'actor.name']))
            ->paginate(TablePage::size(), [
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
            'withholding_account' => [$record->withholding_minor > 0 ? 'required' : 'nullable', 'integer', 'min:1', 'different:cash_account,member_account'],
        ]);
        $withholdingAccount = isset($input['withholding_account']) ? (int) $input['withholding_account'] : null;
        $journal = $this->posting->fundJournal($record, (int) $input['cash_account'], (int) $input['member_account'], $withholdingAccount);
        $request->session()->put('member_fund_journal_preview', [
            'transaction' => $transaction, 'actor' => $request->user()->id, 'token' => Str::random(40),
            'cash' => (int) $input['cash_account'], 'fund' => (int) $input['member_account'],
            'withholding' => $withholdingAccount, 'journal' => $journal,
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
            if (! $record || in_array($record->kind, ['savings_loan_payment', 'exit_savings', 'exit_shares'], true) || DB::table('member_fund_journals')->where('member_fund_transaction_id', $transaction)->exists()) {
                $this->invalid('Only an unposted member transaction can be posted.');
            }
            $journal = $this->posting->fundJournal($record, $preview['cash'], $preview['fund'], $preview['withholding'] ?? null);
            if ($journal !== $preview['journal']) {
                $this->invalid('The transaction changed after preview. Preview the journal again.');
            }

            $this->periods->requireOpenDates([$journal['transaction_date']], 'journal');

            $this->posting->writeFund($transaction, $actor, $journal);
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
        abort_if($record === null || in_array($record->kind, ['savings_loan_payment', 'exit_savings', 'exit_shares'], true), 404);

        return $record;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['journal' => $message]);
    }
}
