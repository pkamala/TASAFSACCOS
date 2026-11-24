<?php

namespace App\Http\Controllers;

use App\BankReconciliations;
use App\LedgerPeriods;
use App\TablePage;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LedgerPeriodController extends Controller
{
    private const Month = 'regex:/\A[2-9]\d{3}-(?:0[1-9]|1[0-2])\z/';

    public function index(Request $request, LedgerPeriods $ledger, BankReconciliations $bank): View
    {
        $today = CarbonImmutable::now('Africa/Dar_es_Salaam');
        $month = $request->validate(['month' => ['nullable', self::Month]])['month'] ?? $today->subMonthNoOverflow()->format('Y-m');
        if ($month > $today->format('Y-m')) {
            throw ValidationException::withMessages(['month' => 'Choose the current month or an earlier month.']);
        }
        [$from, $to] = $this->dates($month);
        $reportTo = min($to, $today->toDateString());
        $ended = $month < $today->format('Y-m');
        $requiresReconciliation = $ledger->requiresBankReconciliation($to);
        $bankStatuses = $ended ? $bank->periodStatuses($from, $to)->countBy('status') : collect();
        $banksPending = $bankStatuses->sum() - $bankStatuses->get(BankReconciliations::Reconciled, 0);
        $unposted = $ledger->unposted($from, $to);
        $closed = $ledger->closed($from);
        $mayClose = ! $closed && $ended && array_sum($unposted) === 0 && (! $requiresReconciliation || $banksPending === 0);
        $history = DB::table('ledger_period_events as event')->where('event.period', $from)
            ->join('users as actor', 'actor.id', '=', 'event.recorded_by')
            ->orderByDesc('event.id')->simplePaginate(TablePage::size(), ['event.*', 'actor.name as actor'])->appends(compact('month'));

        return view('staff.ledger-periods', compact('month', 'from', 'to', 'unposted', 'closed', 'mayClose', 'history', 'reportTo', 'bankStatuses', 'ended', 'requiresReconciliation', 'banksPending'));
    }

    public function record(Request $request, LedgerPeriods $ledger, BankReconciliations $bank): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', self::Month],
            'action' => ['required', 'in:close,reopen'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $month = $data['month'];
        if ($month >= now('Africa/Dar_es_Salaam')->format('Y-m')) {
            throw ValidationException::withMessages(['month' => 'Only an ended month can be closed or reopened.']);
        }
        [$from, $to] = $this->dates($month);

        DB::transaction(function () use ($request, $ledger, $bank, $data, $from, $to): void {
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['chart-accounts']);
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['ledger-cutover']);
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['ledger-period:'.$from]);
            $closing = $data['action'] === 'close';
            if ($ledger->closed($from) === $closing) {
                throw ValidationException::withMessages(['action' => $closing ? 'This period is already closed.' : 'Only a closed period can be reopened.']);
            }
            if ($closing && array_sum($ledger->unposted($from, $to)) > 0) {
                throw ValidationException::withMessages(['action' => 'Post the confirmed transactions for this month before closing the ledger.']);
            }

            if ($closing && $ledger->requiresBankReconciliation($to)) {
                $pending = $bank->periodStatuses($from, $to)->where('status', '!=', BankReconciliations::Reconciled);
                if ($pending->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'action' => 'Complete current bank reconciliations with zero unexplained differences before closing this month.',
                    ]);
                }
            }

            DB::table('ledger_period_events')->insert([
                'period' => $from, 'action' => $closing ? 'closed' : 'reopened',
                'reason' => $data['reason'], 'recorded_by' => $request->user()->id, 'recorded_at' => now(),
            ]);
        }, 3);

        return redirect()->route('staff.ledger-periods.index', compact('month'))
            ->with('status', $data['action'] === 'close' ? 'Ledger period closed.' : 'Ledger period reopened.');
    }

    private function dates(string $month): array
    {
        $start = CarbonImmutable::parse($month.'-01', 'Africa/Dar_es_Salaam');

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }
}
