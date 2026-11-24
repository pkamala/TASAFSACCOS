<?php

namespace App\Http\Controllers;

use App\JournalPosting;
use App\LedgerPeriods;
use App\PayrollPostingAccounts;
use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use stdClass;

class LoanJournalController extends Controller
{
    public function __construct(private LedgerPeriods $periods, private JournalPosting $posting) {}

    public function index(): View
    {
        $postings = DB::table('loan_journal_postings as posting')
            ->join('loan_batches as batch', 'batch.id', '=', 'posting.loan_batch_id')
            ->join('users as actor', 'actor.id', '=', 'posting.posted_by')
            ->orderByDesc('posting.id')->simplePaginate(TablePage::size(), [
                'posting.id', 'posting.loan_batch_id', 'posting.posted_at',
                'batch.kind', 'batch.effective_date', 'actor.name as actor',
            ]);

        return view('staff.loan-journals.index', compact('postings'));
    }

    public function show(Request $request, int $batch, PayrollPostingAccounts $postingAccounts): View
    {
        $record = $this->confirmedBatch($batch);
        $posting = DB::table('loan_journal_postings as posting')->where('posting.loan_batch_id', $batch)
            ->join('users as actor', 'actor.id', '=', 'posting.posted_by')
            ->first(['posting.id', 'posting.posted_at', 'actor.name as actor']);
        $accounts = DB::table('chart_accounts')->where('is_ledger_account', true)->orderBy('code')->orderBy('id')
            ->get(['id', 'code', 'name', 'legacy_id', 'retired_at'])->keyBy('id');
        $offset = $record->kind === 'disbursement' ? 'Financed charges clearing' : 'Interest collection';
        $reversalId = $posting ? DB::table('ledger_journal_reversals')->where('loan_journal_posting_id', $posting->id)->value('id') : null;
        $preview = $request->session()->get('loan_journal_preview');
        if (($preview['batch'] ?? null) !== $batch || ($preview['actor'] ?? null) !== $request->user()->id || $posting) {
            $preview = null;
        }
        $lines = $posting ? DB::table('loan_journal_entries as entry')
            ->join('loan_journal_lines as line', 'line.loan_journal_entry_id', '=', 'entry.id')
            ->where('entry.loan_journal_posting_id', $posting->id)
            ->orderBy('entry.id')->orderByDesc('line.signed_minor')->orderBy('line.id')
            ->get(['entry.loan_application_id', 'entry.transaction_date', 'entry.source_reference', 'line.chart_account_id', 'line.signed_minor']) : collect($preview['lines'] ?? [])->map(static fn ($line): stdClass => (object) $line);

        $defaults = $record->kind === 'repayment' ? $postingAccounts->defaults($accounts) : [];

        return view('staff.loan-journals.show', compact('defaults', 'reversalId', 'record', 'posting', 'accounts', 'offset', 'preview', 'lines'));
    }

    public function preview(Request $request, int $batch): RedirectResponse
    {
        $request->session()->forget('loan_journal_preview');
        $record = $this->confirmedBatch($batch);
        if (DB::table('loan_journal_postings')->where('loan_batch_id', $batch)->exists()) {
            $this->invalid('This payment list has already been posted.');
        }
        $data = $request->validate([
            'cash_account' => ['required', 'integer', 'min:1'],
            'principal_account' => ['required', 'integer', 'min:1'],
            'offset_account' => ['nullable', 'integer', 'min:1'],
        ]);
        $accounts = [
            'cash' => (int) $data['cash_account'],
            'principal' => (int) $data['principal_account'],
            'offset' => isset($data['offset_account']) ? (int) $data['offset_account'] : null,
        ];
        $lines = $this->posting->loanLines($record, $accounts);
        $request->session()->put('loan_journal_preview', [
            'batch' => $batch, 'actor' => $request->user()->id, 'token' => Str::random(40), 'accounts' => $accounts,
            'lines' => $lines, 'digest' => hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR)),
        ]);

        return redirect()->route('staff.loan-journal.show', $batch);
    }

    public function post(Request $request, int $batch): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:40']]);
        $preview = $request->session()->get('loan_journal_preview');
        $actor = $request->user()->id;
        if (! $preview || $preview['actor'] !== $actor || $preview['batch'] !== $batch || ! hash_equals($preview['token'], $data['token'])) {
            $this->invalid('Preview the journal before posting.');
        }

        DB::transaction(function () use ($preview, $actor, $batch): void {
            $record = DB::table('loan_batches')->lockForUpdate()->find($batch);
            if (! $record || $record->status !== 'confirmed' || DB::table('loan_journal_postings')->where('loan_batch_id', $batch)->exists()) {
                $this->invalid('Only an unposted confirmed list can be posted.');
            }
            $lines = $this->posting->loanLines($record, $preview['accounts']);
            if (! hash_equals($preview['digest'], hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR)))) {
                $this->invalid('The transactions changed after preview. Preview the journal again.');
            }

            $this->periods->requireOpenDates(array_column($lines, 'transaction_date'), 'journal');

            $this->posting->writeLoan($batch, $actor, $lines);
        }, 3);

        $request->session()->forget('loan_journal_preview');

        return redirect()->route('staff.loan-journal.show', $batch)->with('status', 'Loan journal posted.');
    }

    private function confirmedBatch(int $batch): stdClass
    {
        $record = DB::table('loan_batches')->where('status', 'confirmed')->find($batch);
        abort_if($record === null, 404);

        return $record;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['journal' => $message]);
    }
}
