<?php

namespace App\Http\Controllers;

use App\JournalPosting;
use App\LedgerPeriods;
use App\PayrollPostingAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use stdClass;

class PayrollJournalController extends Controller
{
    public function __construct(private JournalPosting $posting, private LedgerPeriods $periods) {}

    public function show(Request $request, int $batch, PayrollPostingAccounts $postingAccounts): View
    {
        $record = $this->confirmedPayroll($batch);
        $sources = $this->sources($record);
        $accounts = DB::table('chart_accounts')->where('is_ledger_account', true)
            ->orderBy('code')->orderBy('id')->get(['id', 'code', 'name', 'retired_at'])->keyBy('id');
        $preview = $request->session()->get('payroll_journal_preview');
        if (($preview['batch'] ?? null) !== $batch || ($preview['actor'] ?? null) !== $request->user()->id) {
            $preview = null;
        }

        $defaults = $postingAccounts->defaults($accounts);

        return view('staff.loan-batches.journals', compact('defaults', 'record', 'sources', 'accounts', 'preview'));
    }

    public function preview(Request $request, int $batch): RedirectResponse
    {
        $request->session()->forget('payroll_journal_preview');
        $sources = $this->sources($this->confirmedPayroll($batch));
        $rules = ['cash' => ['required', 'integer', 'min:1']];
        foreach ($sources['fields'] as $field => $label) {
            $rules[$field] = [$field === 'offset' ? 'nullable' : 'required', 'integer', 'min:1'];
        }
        $accounts = array_map(static fn ($id): ?int => $id === null ? null : (int) $id, $request->validate($rules)) + ['offset' => null];
        $selected = array_filter($accounts);
        if (count($selected) !== count(array_unique($selected))) {
            $this->invalid('Select a distinct account for each posting account.');
        }
        $entries = $this->entries($sources, $accounts);

        $request->session()->put('payroll_journal_preview', [
            'batch' => $batch, 'actor' => $request->user()->id, 'token' => Str::random(40),
            'accounts' => $accounts, 'entries' => $entries,
        ]);

        return redirect()->route('staff.payroll-journals.show', $batch);
    }

    public function post(Request $request, int $batch): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:40']]);
        $preview = $request->session()->get('payroll_journal_preview');
        $actor = $request->user()->id;
        if (($preview['batch'] ?? null) !== $batch || ($preview['actor'] ?? null) !== $actor || ! hash_equals($preview['token'], $data['token'])) {
            $this->invalid('Preview the payment list journals before posting.');
        }

        DB::transaction(function () use ($batch, $actor, $preview): void {
            $sources = $this->sources($this->confirmedPayroll($batch, lock: true), lock: true);
            $entries = $this->entries($sources, $preview['accounts']);
            if ($entries !== $preview['entries']) {
                $this->invalid('The outstanding journals changed. Preview the payment list journals again.');
            }

            $this->periods->requireOpenDates([
                ...array_column($entries['loan'], 'transaction_date'),
                ...array_column($entries['funds'], 'transaction_date'),
            ], 'journal');

            if ($entries['loan'] !== []) {
                $this->posting->writeLoan($batch, $actor, array_map(static fn (array $line): stdClass => (object) $line, $entries['loan']));
            }
            foreach ($entries['funds'] as $transaction => $journal) {
                $this->posting->writeFund($transaction, $actor, $journal);
            }
        }, 3);

        $request->session()->forget('payroll_journal_preview');

        return redirect()->route('staff.payroll-journals.show', $batch)->with('status', 'Payment list journals posted.');
    }

    private function confirmedPayroll(int $batch, bool $lock = false): stdClass
    {
        $query = DB::table('loan_batches')->where('kind', 'repayment')->where('status', 'confirmed');
        $record = ($lock ? $query->lockForUpdate() : $query)->find($batch);
        abort_if($record === null, 404);

        return $record;
    }

    private function sources(stdClass $record, bool $lock = false): array
    {
        $items = json_decode($record->items, true, flags: JSON_THROW_ON_ERROR);
        $hasLoans = array_column($items, 'loan_id') !== [];
        $loanPosted = $hasLoans && DB::table('loan_journal_postings')->where('loan_batch_id', $record->id)->exists();
        $query = DB::table('member_fund_transactions')->where('loan_batch_id', $record->id)->orderBy('id');
        $funds = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');
        $postedFunds = DB::table('member_fund_journals')->whereIn('member_fund_transaction_id', $funds->pluck('id'))
            ->pluck('member_fund_transaction_id')->flip();

        $expectedFunds = [];
        foreach ($items as $item) {
            foreach (array_filter($item['funds'] ?? []) as $kind => $amount) {
                $expectedFunds[] = [$item['member_id'], $kind, $amount];
            }
        }
        $actualFunds = $funds->map(fn (stdClass $fund): array => [$fund->member_id, $fund->kind, $fund->signed_minor])->all();
        sort($expectedFunds);
        sort($actualFunds);
        $fundsMatch = $actualFunds === $expectedFunds;

        $pendingFunds = $funds->reject(fn (stdClass $fund): bool => $postedFunds->has($fund->id));
        $fields = $hasLoans && ! $loanPosted ? ['principal' => 'Loans receivable', 'offset' => 'Interest income (if collected)'] : [];
        foreach (['savings_deposit' => 'Member savings', 'share_purchase' => 'Share capital'] as $kind => $label) {
            if ($pendingFunds->contains('kind', $kind)) {
                $fields[$kind] = $label;
            }
        }

        return compact('record', 'hasLoans', 'loanPosted', 'funds', 'postedFunds', 'pendingFunds', 'fields', 'fundsMatch');
    }

    private function entries(array $sources, array $accounts): array
    {
        if (! $sources['fundsMatch']) {
            $this->invalid('The confirmed savings and share transactions do not match the payment list.');
        }
        if ($sources['fields'] === []) {
            $this->invalid('There are no outstanding journals for this payment list.');
        }
        if (array_diff_key($sources['fields'], $accounts) !== []) {
            $this->invalid('The outstanding journals changed. Preview the payment list journals again.');
        }

        $loan = isset($sources['fields']['principal']) ? array_map(
            static fn (stdClass $line): array => (array) $line,
            $this->posting->loanLines($sources['record'], [
                'cash' => $accounts['cash'], 'principal' => $accounts['principal'], 'offset' => $accounts['offset'] ?? null,
            ]),
        ) : [];
        $funds = [];
        foreach ($sources['pendingFunds'] as $fund) {
            $funds[$fund->id] = $this->posting->fundJournal($fund, $accounts['cash'], $accounts[$fund->kind]);
        }

        return compact('loan', 'funds');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['journal' => $message]);
    }
}
