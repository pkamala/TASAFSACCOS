<?php

namespace App\Http\Controllers;

use App\LedgerPeriods;
use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use stdClass;

class LedgerJournalReversalController extends Controller
{
    private const Sources = [
        'loan' => ['loan_journal_postings', 'loan_journal_posting_id'],
        'general' => ['general_journals', 'general_journal_id'],
        'member-fund' => ['member_fund_journals', 'member_fund_journal_id'],
    ];

    public function __construct(private LedgerPeriods $periods) {}

    public function index(): View
    {
        $rows = DB::table('ledger_journal_reversals as reversal')
            ->join('users as actor', 'actor.id', '=', 'reversal.reversed_by')
            ->orderByDesc('reversal.id')->simplePaginate(TablePage::size(), ['reversal.*', 'actor.name as actor']);

        return view('staff.ledger-reversals.index', compact('rows'));
    }

    public function preview(string $source, int $journal): View|RedirectResponse
    {
        [$table, $column] = self::Sources[$source];
        $original = DB::table($table)->find($journal);
        abort_if($original === null, 404);
        $existing = DB::table('ledger_journal_reversals')->where($column, $journal)->value('id');
        if ($existing !== null) {
            return redirect()->route('staff.ledger-reversals.show', $existing);
        }
        $lines = $this->opposite($source, $original);
        $date = $lines->max('original_date');
        $originalUrl = $this->originalUrl($source, $original);
        $reversal = null;
        $today = now('Africa/Dar_es_Salaam')->toDateString();

        return view('staff.ledger-reversals.show', [
            'accounts' => $this->accounts($lines),
            ...compact('source', 'journal', 'lines', 'date', 'today', 'originalUrl', 'reversal'),
        ]);
    }

    public function store(Request $request, string $source, int $journal): RedirectResponse
    {
        [$table, $column] = self::Sources[$source];
        $data = $request->validate([
            'transaction_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $id = DB::transaction(function () use ($request, $table, $column, $data, $source, $journal): int {
            $original = DB::table($table)->lockForUpdate()->find($journal);
            abort_if($original === null, 404);
            if (DB::table('ledger_journal_reversals')->where($column, $journal)->exists()) {
                throw ValidationException::withMessages(['reason' => 'This journal already has a posted reversal.']);
            }
            $lines = $this->opposite($source, $original);
            if ($lines->isEmpty() || $data['transaction_date'] < $lines->max('original_date')) {
                throw ValidationException::withMessages(['transaction_date' => 'Use a date on or after every original entry date.']);
            }

            DB::table('chart_accounts')->whereIn('id', $lines->pluck('chart_account_id'))
                ->orderBy('id')->sharedLock()->get(['id']);
            $this->periods->requireOpenDates([$data['transaction_date']], 'transaction_date');

            $id = DB::table('ledger_journal_reversals')->insertGetId([
                $column => $journal, 'transaction_date' => $data['transaction_date'], 'reason' => $data['reason'],
                'reversed_by' => $request->user()->id, 'reversed_at' => now(),
            ]);
            foreach ($lines->chunk(250) as $batch) {
                DB::table('ledger_journal_reversal_lines')->insert($batch->map(fn ($line): array => [
                    'ledger_journal_reversal_id' => $id, 'chart_account_id' => $line->chart_account_id,
                    'subject_id' => $line->subject_id, 'source_reference' => $line->source_reference, 'signed_minor' => $line->signed_minor,
                ])->values()->all());
            }

            return $id;
        }, 3);

        return redirect()->route('staff.ledger-reversals.show', $id)->with('status', 'Journal reversal posted.');
    }

    public function show(int $reversal): View
    {
        $record = DB::table('ledger_journal_reversals as reversal')
            ->join('users as actor', 'actor.id', '=', 'reversal.reversed_by')
            ->where('reversal.id', $reversal)->first(['reversal.*', 'actor.name as actor']);
        abort_if($record === null, 404);
        $source = match (true) {
            $record->loan_journal_posting_id !== null => 'loan',
            $record->member_fund_journal_id !== null => 'member-fund',
            default => 'general',
        };
        $journal = $record->{self::Sources[$source][1]};
        $original = DB::table(self::Sources[$source][0])->find($journal);
        $lines = DB::table('ledger_journal_reversal_lines')->where('ledger_journal_reversal_id', $reversal)->orderBy('id')->get();

        return view('staff.ledger-reversals.show', [
            'reversal' => $record, 'source' => $source, 'journal' => $journal, 'lines' => $lines,
            'originalUrl' => $this->originalUrl($source, $original), 'accounts' => $this->accounts($lines),
        ]);
    }

    private function opposite(string $source, stdClass $original): Collection
    {
        if ($source === 'loan') {
            return DB::table('loan_journal_entries as entry')
                ->join('loan_journal_lines as line', 'line.loan_journal_entry_id', '=', 'entry.id')
                ->where('entry.loan_journal_posting_id', $original->id)
                ->selectRaw('line.chart_account_id, -line.signed_minor AS signed_minor, entry.transaction_date AS original_date,
                    entry.loan_application_id AS subject_id, entry.source_reference')
                ->orderBy('entry.id')->orderByDesc('line.signed_minor')->orderBy('line.id')->get();
        }

        if ($source === 'general') {
            return DB::table('general_journal_lines')->where('general_journal_id', $original->id)
                ->selectRaw('chart_account_id, -signed_minor AS signed_minor')
                ->selectRaw('CAST(? AS date) AS original_date, CAST(? AS bigint) AS subject_id, CAST(? AS text) AS source_reference',
                    [$original->transaction_date, $original->id, $original->source_reference])
                ->orderBy('id')->get();
        }

        $line = [
            'original_date' => $original->transaction_date, 'subject_id' => $original->member_fund_transaction_id,
            'source_reference' => $original->source_reference,
        ];

        return collect([
            (object) [...$line, 'chart_account_id' => $original->credit_account_id, 'signed_minor' => $original->amount_minor],
            (object) [...$line, 'chart_account_id' => $original->debit_account_id, 'signed_minor' => -$original->amount_minor],
        ]);
    }

    private function accounts(Collection $lines): Collection
    {
        return DB::table('chart_accounts')->whereIn('id', $lines->pluck('chart_account_id'))
            ->get(['id', 'code', 'name', 'legacy_id'])->keyBy('id');
    }

    private function originalUrl(string $source, stdClass $original): string
    {
        return match ($source) {
            'loan' => route('staff.loan-journal.show', $original->loan_batch_id),
            'general' => route('staff.general-journals.show', $original->id),
            'member-fund' => route('staff.member-fund-journals.show', $original->member_fund_transaction_id),
        };
    }
}
