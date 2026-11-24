<?php

namespace App\Http\Controllers;

use App\LedgerPeriods;
use App\TablePage;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GeneralJournalController extends Controller
{
    public function __construct(private LedgerPeriods $periods) {}

    public function index(): View
    {
        $rows = DB::table('general_journals as journal')->join('users', 'users.id', '=', 'journal.posted_by')
            ->orderByDesc('journal.id')->simplePaginate(TablePage::size(), ['journal.id', 'journal.transaction_date', 'journal.category', 'journal.source_reference', 'users.name as actor']);

        return view('staff.general-journals.index', compact('rows'));
    }

    public function create(Request $request): View
    {
        $cashBook = $request->routeIs('staff.cash-book.transactions.*');
        $journalRoutes = $cashBook ? 'staff.cash-book.transactions' : 'staff.general-journals';
        $accounts = $this->activeAccounts()->orderBy('code')->orderBy('id')->get(['id', 'code', 'name']);
        $cashAccountIds = $cashBook ? DB::table('cash_book_accounts')->pluck('chart_account_id')->flip() : collect();
        $hasCashAccounts = $accounts->contains(fn ($account): bool => $cashAccountIds->has($account->id));
        $preview = $request->session()->get($this->previewKey($cashBook));
        $preview = ($preview['actor'] ?? null) === $request->user()->id ? $preview : null;
        $today = now('Africa/Dar_es_Salaam')->toDateString();

        $inputs = collect($request->old('lines', $preview['input'] ?? []))->take(20)
            ->map(fn ($line): array => array_filter(is_array($line) ? $line : [], is_scalar(...)))
            ->values()->pad(2, []);
        $previewLines = collect();
        if ($preview !== null) {
            $previewAccounts = DB::table('chart_accounts')->whereIn('id', array_column($preview['lines'], 'chart_account_id'))
                ->get(['id', 'code', 'name'])->keyBy('id');
            $previewLines = collect($preview['lines'])->map(fn (array $line): object => (object) [
                ...$line, ...(array) $previewAccounts->get($line['chart_account_id']),
            ]);
        }

        return view('staff.general-journals.create', compact('accounts', 'inputs', 'preview', 'previewLines', 'today', 'cashBook', 'journalRoutes', 'cashAccountIds', 'hasCashAccounts'));
    }

    public function preview(Request $request): RedirectResponse
    {
        $cashBook = $request->routeIs('staff.cash-book.transactions.*');
        $previewKey = $this->previewKey($cashBook);
        $request->session()->forget($previewKey);
        $data = $request->validate([
            'transaction_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'category' => ['required', 'in:expense,fee,general'],
            'source_reference' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'list', 'min:2', 'max:20'],
            'lines.*' => ['required', 'array:account,side,amount'],
            'lines.*.account' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.side' => ['required', 'in:debit,credit'],
            'lines.*.amount' => ['required', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
        ]);
        $lines = collect($data['lines'])->map(function (array $line): array {
            [$whole, $fraction] = array_pad(explode('.', $line['amount'], 2), 2, '');

            return [
                'chart_account_id' => (int) $line['account'],
                'signed_minor' => ((int) $whole * 100 + (int) str_pad($fraction, 2, '0')) * ($line['side'] === 'debit' ? 1 : -1),
            ];
        });

        if ($lines->contains(fn (array $line): bool => $line['signed_minor'] === 0) || $lines->sum('signed_minor') !== 0) {
            throw ValidationException::withMessages(['lines' => 'Enter positive amounts and make total debits equal total credits.']);
        }

        $journal = [
            'transaction_date' => $data['transaction_date'], 'category' => $data['category'],
            'source_reference' => $data['source_reference'], 'description' => $data['description'],
        ];
        DB::transaction(function () use ($lines, $journal, $cashBook): void {
            $this->checkAccounts($lines->pluck('chart_account_id')->all(), $cashBook);
            $this->periods->requireOpenDates([$journal['transaction_date']], 'transaction_date');
        });
        $request->session()->put($previewKey, [
            'token' => (string) Str::uuid(), 'actor' => $request->user()->id,
            'journal' => $journal, 'lines' => $lines->all(), 'input' => $data['lines'],
        ]);

        return redirect()->route($cashBook ? 'staff.cash-book.transactions.create' : 'staff.general-journals.create')->withFragment('journal-preview');
    }

    public function post(Request $request): RedirectResponse
    {
        $token = $request->validate(['token' => ['required', 'uuid']])['token'];
        $actor = $request->user()->id;
        $cashBook = $request->routeIs('staff.cash-book.transactions.*');
        $previewKey = $this->previewKey($cashBook);
        $preview = $request->session()->get($previewKey);
        $id = DB::transaction(function () use ($token, $actor, $preview, $cashBook): int {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['general-journal:'.$token]);
            $existing = DB::table('general_journals')->where('preview_token', $token)->where('posted_by', $actor)->value('id');
            if ($existing !== null) {
                return $existing;
            }

            if (($preview['actor'] ?? null) !== $actor || ! hash_equals($preview['token'], $token)) {
                throw ValidationException::withMessages(['journal' => 'Preview the journal before posting.']);
            }
            $this->checkAccounts(array_column($preview['lines'], 'chart_account_id'), $cashBook);
            $this->periods->requireOpenDates([$preview['journal']['transaction_date']], 'transaction_date');

            $id = DB::table('general_journals')->insertGetId([
                ...$preview['journal'], 'preview_token' => $token, 'line_count' => count($preview['lines']),
                'posted_by' => $actor, 'posted_at' => now(),
            ]);
            DB::table('general_journal_lines')->insert(array_map(
                fn (array $line): array => [...$line, 'general_journal_id' => $id],
                $preview['lines'],
            ));

            return $id;
        }, 3);
        if (($preview['token'] ?? null) === $token) {
            $request->session()->forget($previewKey);
        }

        return redirect()->route('staff.general-journals.show', $cashBook ? ['journal' => $id, 'cash_book' => 1] : $id);
    }

    public function show(int $journal): View
    {
        $record = DB::table('general_journals as journal')->join('users', 'users.id', '=', 'journal.posted_by')
            ->where('journal.id', $journal)->first(['journal.id', 'journal.transaction_date', 'journal.category',
                'journal.source_reference', 'journal.description', 'journal.posted_at', 'users.name as actor']);
        abort_if($record === null, 404);
        $lines = DB::table('general_journal_lines as line')->join('chart_accounts as account', 'account.id', '=', 'line.chart_account_id')
            ->where('line.general_journal_id', $journal)->orderBy('line.id')
            ->get(['line.chart_account_id', 'line.signed_minor', 'account.code', 'account.name']);

        return view('staff.general-journals.show', compact('record', 'lines'));
    }

    private function activeAccounts(): Builder
    {
        return DB::table('chart_accounts')->where('is_ledger_account', true)->whereNull('retired_at');
    }

    private function previewKey(bool $cashBook): string
    {
        return $cashBook ? 'cash_book_transaction_preview' : 'general_journal_preview';
    }

    private function checkAccounts(array $ids, bool $cashBook): void
    {
        if ($cashBook) {
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['chart-accounts']);
            if (! DB::table('cash_book_accounts')->whereIn('chart_account_id', $ids)->exists()) {
                throw ValidationException::withMessages(['lines' => 'Select at least one account included in the cash book.']);
            }
        }

        $accounts = $this->activeAccounts()->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id']);
        if ($accounts->count() !== count($ids)) {
            throw ValidationException::withMessages(['lines' => 'Use a different active ledger account on each line.']);
        }
    }
}
