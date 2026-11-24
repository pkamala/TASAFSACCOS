<?php

namespace App\Http\Controllers;

use App\BankReconciliations;
use App\LedgerPeriods;
use App\TablePage;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BankReconciliationController extends Controller
{
    private const Month = 'regex:/\A[2-9]\d{3}-(?:0[1-9]|1[0-2])\z/';

    public function index(Request $request, BankReconciliations $bank, LedgerPeriods $ledger): View
    {
        $data = $request->validate(['month' => ['nullable', self::Month], 'account' => ['nullable', 'integer', 'min:1']]);
        $month = $data['month'] ?? now('Africa/Dar_es_Salaam')->subMonthNoOverflow()->format('Y-m');
        [$period, $end] = $this->dates($month);
        $reviewLocked = $ledger->requiresBankReconciliation($end) && $ledger->closed($period);
        $accounts = $bank->accounts();
        $account = isset($data['account']) ? $accounts->get($data['account']) : null;
        abort_if(isset($data['account']) && $account === null, 404);

        $lines = $bank->lines($accounts->keys()->all(), $end);
        $latest = $bank->latest($period);
        $statuses = $bank->statuses($accounts, $lines, $latest, $period, $end);
        $journalLines = $lines->get($account?->id, collect());
        $candidates = $bank->candidates($journalLines);
        $bookMinor = (int) $journalLines->sum('signed_minor');
        $review = $account === null ? null : DB::table('bank_reconciliations')
            ->where('chart_account_id', $account->id)->where('period', '<=', $period)
            ->orderByDesc('period')->orderByDesc('id')->first();
        $current = $review?->period === $period ? $review : null;
        $basis = $this->basis($account?->id, $period, $bank->fingerprint($journalLines), $current?->id);
        $priorKeys = $this->keys($review?->cleared_keys ?? '[]');
        $oldKeys = $request->old('cleared');
        $selected = array_fill_keys(is_string($oldKeys) ? $this->keys($oldKeys) : $priorKeys, true);
        $history = $account === null ? null : DB::table('bank_reconciliations as review')
            ->join('users as actor', 'actor.id', '=', 'review.reviewed_by')
            ->where('review.chart_account_id', $account->id)->where('review.period', $period)->orderByDesc('review.id')
            ->simplePaginate(TablePage::size(), ['review.*', 'actor.name as actor'])->appends(['account' => $account->id, 'month' => $month]);

        return view('staff.bank-reconciliation', compact(
            'month', 'end', 'account', 'statuses', 'candidates', 'bookMinor', 'current', 'basis', 'selected', 'history', 'reviewLocked',
        ));
    }

    public function store(Request $request, BankReconciliations $bank, LedgerPeriods $ledger): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', self::Month], 'account' => ['required', 'integer', 'min:1'],
            'statement' => ['required', 'regex:/\A-?\d{1,12}(?:\.\d{1,2})?\z/'],
            'statement_reference' => ['required', 'string', 'max:100'], 'review_note' => ['nullable', 'string', 'max:500'],
            'cleared' => ['required', 'string', 'json', 'max:1000000'], 'basis' => ['required', 'string', 'size:64'],
        ]);
        $cleared = json_decode($data['cleared']);
        Validator::make(['cleared' => $cleared], [
            'cleared' => ['array', 'list'], 'cleared.*' => ['string', 'distinct', 'regex:/\A[a-z_]+:[0-9]+\z/'],
        ])->validate();
        [$period, $end] = $this->dates($data['month']);
        $account = (int) $data['account'];
        $statementMinor = $this->minor($data['statement']);

        DB::transaction(function () use ($request, $bank, $ledger, $data, $cleared, $period, $end, $account, $statementMinor): void {
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['chart-accounts']);
            abort_unless($bank->accounts()->has($account), 404);
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['ledger-cutover']);
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['ledger-period:'.$period]);
            if ($ledger->requiresBankReconciliation($end) && $ledger->closed($period)) {
                throw ValidationException::withMessages(['month' => 'Reopen the ledger month before revising its bank reconciliation.']);
            }
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ["bank-reconciliation:$account:$period"]);
            $lines = $bank->lines([$account], $end)->get($account, collect());
            $latest = DB::table('bank_reconciliations')->where('chart_account_id', $account)->where('period', $period)->max('id');
            $fingerprint = $bank->fingerprint($lines);
            if (! hash_equals($this->basis($account, $period, $fingerprint, $latest), $data['basis'])) {
                throw ValidationException::withMessages(['cleared' => 'The ledger or saved review changed. Reload the month and check your matches again.']);
            }

            $candidates = $bank->candidates($lines);
            if (array_diff($cleared, $candidates->keys()->all()) !== []) {
                throw ValidationException::withMessages(['cleared' => 'Match only cash-book entries for this bank account through the statement date.']);
            }
            $outstanding = $candidates->except($cleared);
            $record = (object) [
                'book_minor' => (int) $lines->sum('signed_minor'),
                'statement_minor' => $statementMinor,
                'outstanding_deposits_minor' => (int) $outstanding->where('signed_minor', '>', 0)->sum('signed_minor'),
                'outstanding_payments_minor' => -(int) $outstanding->where('signed_minor', '<', 0)->sum('signed_minor'),
            ];
            if ($bank->difference($record) !== 0 && empty($data['review_note'])) {
                throw ValidationException::withMessages(['review_note' => 'Record a follow-up note for an unresolved difference.']);
            }

            DB::table('bank_reconciliations')->insert([
                ...(array) $record, 'chart_account_id' => $account, 'period' => $period,
                'cleared_keys' => json_encode($cleared, JSON_THROW_ON_ERROR),
                'outstanding_lines' => json_encode($outstanding->values()->all(), JSON_THROW_ON_ERROR),
                'ledger_sha256' => $fingerprint, 'statement_reference' => $data['statement_reference'],
                'review_note' => $data['review_note'] ?? null, 'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        }, 3);

        return redirect()->route('staff.bank-reconciliation.index', ['month' => $data['month'], 'account' => $account])
            ->with('status', 'Bank reconciliation review saved.');
    }

    private function dates(string $month): array
    {
        $end = CarbonImmutable::parse($month.'-01', 'Africa/Dar_es_Salaam')->endOfMonth();
        if ($end->toDateString() >= now('Africa/Dar_es_Salaam')->toDateString()) {
            throw ValidationException::withMessages(['month' => 'Choose an ended month.']);
        }

        return [$month.'-01', $end->toDateString()];
    }

    private function keys(string $json): array
    {
        $keys = json_decode($json);

        return is_array($keys) && array_is_list($keys) && count(array_filter($keys, is_string(...))) === count($keys) ? $keys : [];
    }

    private function basis(?int $account, string $period, string $fingerprint, int|string|null $latest): string
    {
        return hash('sha256', $account.'|'.$period.'|'.$fingerprint.'|'.$latest);
    }

    private function minor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return str_starts_with($amount, '-') ? -$minor : $minor;
    }
}
