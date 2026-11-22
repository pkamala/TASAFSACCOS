<?php

namespace App\Http\Controllers;

use App\LedgerPeriods;
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
            ->orderByDesc('journal.id')->simplePaginate(25, ['journal.id', 'journal.transaction_date', 'journal.category', 'journal.source_reference', 'users.name as actor']);

        return view('staff.general-journals.index', compact('rows'));
    }

    public function create(Request $request): View
    {
        $accounts = $this->activeAccounts()->orderBy('code')->orderBy('id')->get(['id', 'code', 'name']);
        $preview = $request->session()->get('general_journal_preview');
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

        return view('staff.general-journals.create', compact('accounts', 'inputs', 'preview', 'previewLines', 'today'));
    }

    public function preview(Request $request): RedirectResponse
    {
        $request->session()->forget('general_journal_preview');
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
        DB::transaction(function () use ($lines, $journal): void {
            $this->checkAccounts($lines->pluck('chart_account_id')->all());
            $this->periods->requireOpenDates([$journal['transaction_date']], 'transaction_date');
        });
        $request->session()->put('general_journal_preview', [
            'token' => (string) Str::uuid(), 'actor' => $request->user()->id,
            'journal' => $journal, 'lines' => $lines->all(), 'input' => $data['lines'],
        ]);

        return redirect()->route('staff.general-journals.create')->withFragment('journal-preview');
    }

    public function post(Request $request): RedirectResponse
    {
        $token = $request->validate(['token' => ['required', 'uuid']])['token'];
        $actor = $request->user()->id;
        $preview = $request->session()->get('general_journal_preview');
        $id = DB::transaction(function () use ($token, $actor, $preview): int {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['general-journal:'.$token]);
            $existing = DB::table('general_journals')->where('preview_token', $token)->where('posted_by', $actor)->value('id');
            if ($existing !== null) {
                return $existing;
            }

            if (($preview['actor'] ?? null) !== $actor || ! hash_equals($preview['token'], $token)) {
                throw ValidationException::withMessages(['journal' => 'Preview the journal before posting.']);
            }
            $this->checkAccounts(array_column($preview['lines'], 'chart_account_id'));
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
            $request->session()->forget('general_journal_preview');
        }

        return redirect()->route('staff.general-journals.show', $id);
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

    private function checkAccounts(array $ids): void
    {
        $accounts = $this->activeAccounts()->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id']);
        if ($accounts->count() !== count($ids)) {
            throw ValidationException::withMessages(['lines' => 'Use a different active ledger account on each line.']);
        }
    }
}
