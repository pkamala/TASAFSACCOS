<?php

namespace App\Http\Controllers;

use App\ChartPostingAccounts;
use App\LedgerPeriods;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use stdClass;

class LoanJournalController extends Controller
{
    public function __construct(private LedgerPeriods $periods, private ChartPostingAccounts $chart) {}

    public function index(): View
    {
        $postings = DB::table('loan_journal_postings as posting')
            ->join('loan_batches as batch', 'batch.id', '=', 'posting.loan_batch_id')
            ->join('users as actor', 'actor.id', '=', 'posting.posted_by')
            ->orderByDesc('posting.id')->simplePaginate(25, [
                'posting.id', 'posting.loan_batch_id', 'posting.posted_at',
                'batch.kind', 'batch.effective_date', 'actor.name as actor',
            ]);

        return view('staff.loan-journals.index', compact('postings'));
    }

    public function show(Request $request, int $batch): View
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
            ->get(['entry.loan_application_id', 'entry.transaction_date', 'entry.source_reference', 'line.chart_account_id', 'line.signed_minor']) : collect($preview['lines'] ?? []);

        return view('staff.loan-journals.show', compact('reversalId', 'record', 'posting', 'accounts', 'offset', 'preview', 'lines'));
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
        $lines = $this->lines($record, $accounts);
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
            $lines = $this->lines($record, $preview['accounts']);
            if (! hash_equals($preview['digest'], hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR)))) {
                $this->invalid('The transactions changed after preview. Preview the journal again.');
            }

            $this->periods->requireOpenDates(array_column($lines, 'transaction_date'), 'journal');

            $posting = DB::table('loan_journal_postings')->insertGetId([
                'loan_batch_id' => $batch, 'posted_by' => $actor, 'posted_at' => now(),
            ]);
            foreach (collect($lines)->groupBy('loan_application_id') as $loan => $journal) {
                $first = $journal->first();
                $entry = DB::table('loan_journal_entries')->insertGetId([
                    'loan_journal_posting_id' => $posting, 'loan_application_id' => $loan,
                    'source_reference' => $first->source_reference, 'transaction_date' => $first->transaction_date,
                ]);

                DB::table('loan_journal_lines')->insert($journal->map(fn ($line): array => [
                    'loan_journal_entry_id' => $entry,
                    'chart_account_id' => $line->chart_account_id,
                    'signed_minor' => $line->signed_minor,
                ])->all());
            }
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

    private function lines(stdClass $batch, array $accounts): array
    {
        $selected = array_values(array_filter($accounts));
        if (count($selected) !== count(array_unique($selected))
            || ! $this->chart->available($selected)) {
            $this->invalid('Select distinct posting accounts from the chart of accounts.');
        }

        $expected = collect(json_decode($batch->items, true, flags: JSON_THROW_ON_ERROR))
            ->filter(fn (array $item): bool => isset($item['loan_id']))->keyBy('loan_id');
        $disbursement = $batch->kind === 'disbursement';
        $transactions = $disbursement
            ? DB::table('loan_disbursements as transfer')->where('transfer.loan_batch_id', $batch->id)
                ->join('loan_applications as loan', 'loan.id', '=', 'transfer.loan_application_id')
                ->selectRaw('loan.id AS loan_id, transfer.transferred_on AS dated, transfer.transfer_reference AS reference, transfer.cash_amount_minor AS cash')
                ->selectRaw('loan.insurance_minor + loan.processing_fee_minor + loan.other_charges_minor AS offset')
                ->orderBy('loan.id')->get()
            : DB::table('loan_repayment_receipts as receipt')->where('receipt.loan_batch_id', $batch->id)
                ->join('loan_repayment_allocations as allocation', 'allocation.loan_repayment_receipt_id', '=', 'receipt.id')
                ->groupBy('receipt.id')
                ->selectRaw('receipt.loan_application_id AS loan_id, receipt.received_on AS dated, receipt.receipt_reference AS reference, receipt.amount_minor AS cash')
                ->selectRaw('sum(allocation.principal_minor) AS principal, sum(allocation.interest_minor) AS offset')
                ->orderBy('receipt.loan_application_id')->get();

        if ($transactions->isEmpty() || $transactions->count() !== $expected->count()
            || $transactions->pluck('loan_id')->duplicates()->isNotEmpty()) {
            $this->invalid('The confirmed transactions do not match the payment list.');
        }

        $lines = [];
        foreach ($transactions as $transaction) {
            $cash = (int) $transaction->cash;
            $offset = (int) $transaction->offset;
            $principal = $disbursement ? $cash + $offset : (int) $transaction->principal;
            if ((int) ($expected->get($transaction->loan_id)['amount'] ?? 0) !== $cash || $cash <= 0
                || $offset < 0 || $principal < 0 || (! $disbursement && $cash !== $principal + $offset)) {
                $this->invalid("Loan {$transaction->loan_id} has inconsistent confirmed amounts.");
            }
            if ($offset > 0 && $accounts['offset'] === null) {
                $this->invalid('Select the offset account for the financed charges or collected interest.');
            }

            $movement = [
                'cash' => $disbursement ? -$cash : $cash,
                'principal' => $disbursement ? $principal : -$principal,
                'offset' => -$offset,
            ];
            foreach (array_filter($movement) as $key => $signedMinor) {
                $lines[] = (object) [
                    'loan_application_id' => $transaction->loan_id,
                    'transaction_date' => $transaction->dated,
                    'source_reference' => $transaction->reference,
                    'chart_account_id' => $accounts[$key],
                    'signed_minor' => $signedMinor,
                ];
            }
        }

        return $lines;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['journal' => $message]);
    }
}
