<?php

namespace App\Http\Controllers;

use App\LedgerPeriods;
use App\LedgerReportData;
use App\TablePage;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use stdClass;

class ChartAccountTransferController extends Controller
{
    public function index(Request $request, int $account): View
    {
        [$original, $replacement] = $this->accounts($account);
        $preview = $this->previewFor($request, $account);
        $today = now('Africa/Dar_es_Salaam')->toDateString();
        $history = DB::table('chart_account_transfers as transfer')
            ->join('users as actor', 'actor.id', '=', 'transfer.posted_by')
            ->where('transfer.original_account_id', $account)
            ->orderByDesc('transfer.id')
            ->tap(TablePage::search(['transfer.transaction_date', 'transfer.reason', 'actor.name']))
            ->paginate(TablePage::size(), ['transfer.*', 'actor.name as actor']);

        return view('staff.chart-account-transfer', compact('original', 'replacement', 'preview', 'today', 'history'));
    }

    public function preview(Request $request, int $account, LedgerReportData $ledger, LedgerPeriods $periods): RedirectResponse
    {
        $request->session()->forget('account_transfer_preview');
        $data = $request->validate([
            'transaction_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        [$original, $replacement] = $this->accounts($account);
        $date = $data['transaction_date'];
        $balance = DB::transaction(function () use ($account, $original, $replacement, $date, $ledger, $periods): int {
            $periods->requireOpenDates([$date], 'transaction_date');

            return $this->balance($account, $original, $replacement, $date, $ledger);
        });

        $request->session()->put('account_transfer_preview', [
            'account' => $account, 'replacement' => $replacement->id, 'date' => $date, 'signed_minor' => $balance,
            'reason' => $data['reason'], 'actor' => $request->user()->id, 'token' => Str::random(40),
        ]);

        return redirect()->route('staff.chart-accounts.transfer', $account)->withFragment('transfer-preview');
    }

    public function post(Request $request, int $account, LedgerReportData $ledger, LedgerPeriods $periods): RedirectResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'size:40']])['token'];
        $preview = $this->previewFor($request, $account);
        if (! $preview || ! hash_equals($preview['token'], $token)) {
            $this->invalid('Preview the balance transfer before posting.');
        }

        $id = DB::transaction(function () use ($account, $preview, $ledger, $periods): int {
            DB::table('chart_accounts')->whereIn('id', [$account, $preview['replacement']])->orderBy('id')->lockForUpdate()->get(['id']);
            [$original, $replacement] = $this->accounts($account);
            $date = $preview['date'];
            $periods->requireOpenDates([$date], 'transaction_date');
            $balance = $this->balance($account, $original, $replacement, $date, $ledger);
            if ($replacement->id !== $preview['replacement'] || $balance !== $preview['signed_minor']) {
                $this->invalid('The account balance or replacement changed. Preview the transfer again.');
            }

            return DB::table('chart_account_transfers')->insertGetId([
                'original_account_id' => $account, 'replacement_account_id' => $replacement->id,
                'signed_minor' => $balance, 'transaction_date' => $date, 'reason' => $preview['reason'],
                'posted_by' => $preview['actor'], 'posted_at' => now(),
            ]);
        }, 3);
        $request->session()->forget('account_transfer_preview');

        return redirect()->route('staff.chart-account-transfers.show', $id)->with('status', 'Account balance transfer posted.');
    }

    public function show(int $transfer): View
    {
        $record = DB::table('chart_account_transfers as transfer')
            ->join('users as actor', 'actor.id', '=', 'transfer.posted_by')
            ->where('transfer.id', $transfer)->first(['transfer.*', 'actor.name as actor']);
        abort_if($record === null, 404);
        $accounts = DB::table('chart_accounts')->whereIn('id', [$record->original_account_id, $record->replacement_account_id])
            ->get(['id', 'code', 'name'])->keyBy('id');
        $original = $accounts->get($record->original_account_id);
        $replacement = $accounts->get($record->replacement_account_id);

        return view('staff.chart-account-transfer-posted', compact('record', 'original', 'replacement'));
    }

    private function accounts(int $account): array
    {
        $original = DB::table('chart_accounts')->whereNotNull('retired_at')->find($account);
        $replacement = DB::table('chart_accounts')->where('replaces_account_id', $account)->first();
        abort_if($original === null || $replacement === null, 404);

        return [$original, $replacement];
    }

    private function balance(int $account, stdClass $original, stdClass $replacement, string $date, LedgerReportData $ledger): int
    {
        if (! $original->is_ledger_account || ! $replacement->is_ledger_account || $replacement->retired_at !== null) {
            $this->invalid('The original and its active replacement must be ledger accounts.');
        }
        $retiredOn = CarbonImmutable::parse($original->retired_at)->setTimezone('Africa/Dar_es_Salaam')->toDateString();
        if ($date < $retiredOn) {
            $this->invalid('Use a date on or after the account retirement date.');
        }
        $lines = $ledger->lines()->where('line.chart_account_id', $account);
        if ((clone $lines)->where('line.transaction_date', '>', $date)->exists()) {
            $this->invalid('Use a date on or after the original account’s latest ledger activity.');
        }
        $balance = (int) $lines->sum('line.signed_minor');
        if ($balance === 0) {
            $this->invalid('The original account has no balance to transfer.');
        }

        return $balance;
    }

    private function previewFor(Request $request, int $account): ?array
    {
        $preview = $request->session()->get('account_transfer_preview');

        return ($preview['actor'] ?? null) === $request->user()->id && ($preview['account'] ?? null) === $account ? $preview : null;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['transfer' => $message]);
    }
}
