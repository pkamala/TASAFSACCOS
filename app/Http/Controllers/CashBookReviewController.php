<?php

namespace App\Http\Controllers;

use App\ChartPostingAccounts;
use App\JournalPosting;
use App\LedgerPeriods;
use App\LedgerReportData;
use App\TablePage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use stdClass;

class CashBookReviewController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,matched,approved,posted'],
        ]);
        $month = $filters['month'] ?? '';
        $status = $filters['status'] ?? '';
        $entries = DB::table('cash_book_review_entries')
            ->when($month !== '', fn ($query) => $query->where('month', $month.'-01'))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->tap(TablePage::search(['source_key', 'payee', 'description', 'payment_reference', 'workflow']))
            ->orderBy('month')->orderBy('source_row')->orderBy('id')->paginate(TablePage::size())->withQueryString();
        $approved = DB::table('cash_book_review_entries')->where('status', 'approved')
            ->when($month !== '', fn ($query) => $query->where('month', $month.'-01'))->count();

        return view('staff.cash-book-review.index', compact('entries', 'month', 'status', 'approved'));
    }

    public function show(int $entry): View
    {
        $record = DB::table('cash_book_review_entries')->find($entry);
        abort_if($record === null, 404);
        $lines = json_decode($record->source_lines, true, flags: JSON_THROW_ON_ERROR);
        $matches = json_decode($record->match_candidates, true, flags: JSON_THROW_ON_ERROR);
        $accounts = DB::table('chart_accounts')->where('is_ledger_account', true)->whereNull('retired_at')
            ->orderBy('code')->get(['id', 'code', 'name']);
        $approved = $record->approved_lines === null ? [] : json_decode($record->approved_lines, true, flags: JSON_THROW_ON_ERROR);

        return view('staff.cash-book-review.show', compact('record', 'lines', 'matches', 'accounts', 'approved'));
    }

    public function review(Request $request, int $entry, LedgerReportData $ledger, LedgerPeriods $periods, ChartPostingAccounts $chart): RedirectResponse
    {
        $input = $request->validate([
            'decision' => ['required', 'in:match,approve,reopen,hold'],
            'match' => ['required_if:decision,match', 'nullable', 'string', 'max:100'],
            'review_note' => ['required', 'string', 'max:500'],
            'values' => ['sometimes', 'in:workbook,corrected'],
            'ledger_acknowledged' => ['sometimes', 'accepted'],
            'transaction_date' => ['exclude_if:values,workbook', 'required_if:decision,approve', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'accounts' => ['exclude_if:values,workbook', 'required_if:decision,approve', 'array', 'list', 'max:100'],
            'accounts.*' => ['exclude_if:values,workbook', 'required_if:decision,approve', 'nullable', 'integer', 'min:1'],
            'amounts' => ['exclude_if:values,workbook', 'sometimes', 'array', 'list', 'max:100'],
            'amounts.*' => ['exclude_if:values,workbook', 'required', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
        ]);

        try {
            DB::transaction(function () use ($request, $entry, $input, $ledger, $periods, $chart): void {
                $record = DB::table('cash_book_review_entries')->lockForUpdate()->find($entry);
                abort_if($record === null, 404);
                if (($input['decision'] === 'reopen' && ! in_array($record->status, ['approved', 'matched'], true))
                    || ($input['decision'] !== 'reopen' && $record->status !== 'pending')) {
                    $this->invalid('This row has changed. Reload it before making another decision.');
                }

                $changes = match ($input['decision']) {
                    'match' => $this->matchJournal($record, $input['match'], $ledger),
                    'approve' => $this->approve($record, $input, $periods, $chart),
                    'reopen', 'hold' => ['status' => 'pending'],
                };
                DB::table('cash_book_review_entries')->where('id', $entry)->update([
                    'matched_journal' => null, 'approved_lines' => null, 'approved_date' => null, ...$changes,
                    'review_note' => $input['review_note'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->invalid('That journal has already been matched to another workbook row.');
        }

        return back()->with('status', 'Cashbook review saved.');
    }

    public function post(Request $request, LedgerPeriods $periods, ChartPostingAccounts $chart, JournalPosting $posting, ?int $entry = null): RedirectResponse
    {
        $month = $entry === null ? $request->validate(['month' => ['required', 'date_format:Y-m']])['month'] : null;
        $count = DB::transaction(function () use ($request, $month, $entry, $periods, $chart, $posting): int {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['cash-book-review-post']);
            $entries = DB::table('cash_book_review_entries')->where('status', 'approved')
                ->when($entry === null, fn ($query) => $query->where('month', $month.'-01'), fn ($query) => $query->where('id', $entry))
                ->orderBy('id')->lockForUpdate()->get();

            $periods->requireOpenDates($entries->pluck('approved_date')->all(), 'transaction_date');
            foreach ($entries as $record) {
                $lines = json_decode($record->approved_lines, true, flags: JSON_THROW_ON_ERROR);
                $this->checkAccounts($lines, $chart);
                if (DB::table('general_journals')->where('source_reference', $record->source_key)->exists()) {
                    $this->invalid("{$record->source_key} already exists in the general ledger.");
                }
                $journal = $posting->writeGeneral($request->user()->id, (string) Str::uuid(), [
                    'transaction_date' => $record->approved_date, 'category' => 'general',
                    'source_reference' => $record->source_key,
                    'description' => Str::limit(implode(' · ', array_filter([$record->description, $record->payee, $record->payment_reference])), 500, '') ?: $record->source_key,
                ], $lines);
                DB::table('cash_book_review_entries')->where('id', $record->id)->update([
                    'status' => 'posted', 'general_journal_id' => $journal,
                ]);
            }

            return $entries->count();
        }, 3);

        if ($entry !== null) {
            return redirect()->route('staff.cash-book-review.show', $entry)
                ->with('status', $count === 0 ? 'This entry has no approved journal to post.' : 'Cashbook journal posted.');
        }

        return back()->with('status', $count === 0 ? 'There are no approved entries to post.' : "$count approved cashbook entries posted.");
    }

    private function matchJournal(stdClass $record, string $key, LedgerReportData $ledger): array
    {
        $candidate = collect(json_decode($record->match_candidates, true, flags: JSON_THROW_ON_ERROR))->firstWhere('key', $key);
        if ($candidate === null) {
            $this->invalid('Select a journal candidate from this workbook row.');
        }

        [$source, $id] = explode(':', $key, 2);
        $bankAmounts = collect(json_decode($record->source_lines, true, flags: JSON_THROW_ON_ERROR))
            ->filter(fn (array $line): bool => $line['bank_code'] !== null)->pluck('signed_minor');
        $lines = $ledger->lines()->where('line.source_kind', $source)->where($source === 'general' ? 'line.subject_id' : 'line.entry_id', (int) $id)
            ->where('line.source_reference', $candidate['reference'])->where('line.transaction_date', $record->transaction_date)
            ->whereIn('line.signed_minor', $bankAmounts)
            ->whereIn('line.chart_account_id', DB::table('cash_book_accounts')->select('chart_account_id'))->get();
        $matchingAmounts = $lines->pluck('signed_minor')->countBy();
        $missingAmount = $bankAmounts->countBy()->contains(fn (int $count, int|string $amount): bool => $matchingAmounts->get($amount, 0) < $count);
        $reversalColumn = match ($source) {
            'loan' => 'loan_journal_posting_id',
            'member_fund' => 'member_fund_journal_id',
            default => 'general_journal_id',
        };
        $reversalId = $source === 'loan'
            ? DB::table('loan_journal_entries')->where('id', (int) $id)->value('loan_journal_posting_id') : (int) $id;
        if ($bankAmounts->isEmpty() || $missingAmount || DB::table('ledger_journal_reversals')->where($reversalColumn, $reversalId)->exists()) {
            $this->invalid('This journal is unavailable or no longer matches the source date and bank amount.');
        }

        return ['status' => 'matched', 'matched_journal' => $key];
    }

    private function approve(stdClass $record, array $input, LedgerPeriods $periods, ChartPostingAccounts $chart): array
    {
        $matches = json_decode($record->match_candidates, true, flags: JSON_THROW_ON_ERROR);
        if (($record->workflow !== 'General journal' || $matches !== []) && ! isset($input['ledger_acknowledged'])) {
            $this->invalid('Confirm that you checked for an existing posting and that this approval is for the general ledger only.');
        }

        $source = json_decode($record->source_lines, true, flags: JSON_THROW_ON_ERROR);
        $fromWorkbook = ($input['values'] ?? 'corrected') === 'workbook';
        $date = $fromWorkbook ? $record->transaction_date : $input['transaction_date'];
        $amounts = $fromWorkbook ? null : ($input['amounts'] ?? null);
        if ($date === null || substr($date, 0, 7) !== substr($record->month, 0, 7)
            || $date > now('Africa/Dar_es_Salaam')->toDateString()) {
            $this->invalid('Enter a valid effective date within the workbook month.');
        }

        if ($fromWorkbook) {
            $mapped = DB::table('chart_accounts')->where('is_ledger_account', true)->whereNull('retired_at')
                ->whereIn('code', array_column($source, 'code'))->get(['id', 'code'])->groupBy('code');
            $accountIds = [];
            foreach ($source as $line) {
                $accounts = $mapped->get($line['code'], collect());
                if ($accounts->count() !== 1) {
                    $this->invalid('Some workbook lines need an account mapping. Use corrected values to select their accounts.');
                }
                $accountIds[] = (int) $accounts->first()->id;
            }
        } else {
            $accountIds = array_map('intval', $input['accounts']);
            if (count($source) !== count($accountIds) || ($amounts !== null && count($source) !== count($amounts))) {
                $this->invalid('Map every workbook line and enter its corrected amount.');
            }
        }

        $codes = DB::table('chart_accounts')->whereIn('id', $accountIds)->pluck('code', 'id');
        $grouped = [];
        foreach ($source as $index => $line) {
            $account = $accountIds[$index];
            if ($line['bank_code'] !== null && $codes->get($account) !== $line['bank_code']) {
                $this->invalid('Use the configured bank account for each bank line.');
            }
            $amount = $line['signed_minor'];
            if ($amounts !== null) {
                [$whole, $fraction] = array_pad(explode('.', $amounts[$index], 2), 2, '');
                $minor = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
                if ($minor === 0) {
                    $this->invalid('Enter a positive amount on every proposed journal line.');
                }
                $amount = $line['signed_minor'] < 0 ? -$minor : $minor;
            }
            $grouped[$account] = ($grouped[$account] ?? 0) + $amount;
        }
        $lines = [];
        foreach (array_filter($grouped) as $account => $amount) {
            $lines[] = ['chart_account_id' => $account, 'signed_minor' => $amount];
        }
        $this->checkAccounts($lines, $chart);
        $periods->requireOpenDates([$date], 'transaction_date');

        return ['status' => 'approved', 'approved_date' => $date, 'approved_lines' => json_encode($lines, JSON_THROW_ON_ERROR)];
    }

    private function checkAccounts(array $lines, ChartPostingAccounts $chart): void
    {
        DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['chart-accounts']);
        $accounts = array_column($lines, 'chart_account_id');
        if (count($lines) < 2 || count($lines) > 20 || array_sum(array_column($lines, 'signed_minor')) !== 0 || ! $chart->available($accounts)
            || ! DB::table('cash_book_accounts')->whereIn('chart_account_id', $accounts)->exists()) {
            $this->invalid('The journal needs balanced amounts, active accounts, and at least one cashbook account.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['review' => $message]);
    }
}
