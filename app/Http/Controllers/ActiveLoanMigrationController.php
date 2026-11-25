<?php

namespace App\Http\Controllers;

use App\ActiveLoanMigration;
use App\LegacyLoanBalanceReport;
use App\TablePage;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActiveLoanMigrationController extends Controller
{
    public function index(Request $request, LegacyLoanBalanceReport $reports): View
    {
        $preview = $this->current($request);
        $snapshots = $reports->snapshots();
        $cutover = DB::table('ledger_opening_journals')->value('effective_on');
        $pending = $cutover === null ? null : DB::table('verified_legacy_loan_balances as balance')
            ->where('balance.as_of', CarbonImmutable::parse($cutover)->subDay()->toDateString())
            ->where('balance.principal_minor', '>', 0)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('active_loan_migrations as migrated')
                ->whereColumn('migrated.verified_balance_id', 'balance.id'))
            ->selectRaw('count(*) AS loans, coalesce(sum(balance.principal_minor), 0) AS principal_minor')->first();
        $history = DB::table('active_loan_migrations as migration')
            ->join('loan_applications as loan', 'loan.id', '=', 'migration.loan_application_id')
            ->join('members as member', 'member.id', '=', 'loan.member_id')
            ->join('users as actor', 'actor.id', '=', 'loan.created_by')
            ->orderByDesc('loan.id')
            ->tap(TablePage::search(['migration.source_id', 'migration.effective_on', 'migration.review_reference', 'member.registration_no', 'actor.name']))
            ->paginate(TablePage::size(), [
                'migration.source_id', 'migration.effective_on', 'migration.review_reference', 'loan.id', 'loan.member_id',
                'loan.principal_minor', 'member.registration_no', 'actor.name as actor', 'loan.created_at',
            ]);
        $ready = $preview !== null && collect($preview['plan'])->every(fn (array $row): bool => $row['status'] === 'Ready');

        return view('staff.active-loan-migration', compact('preview', 'snapshots', 'cutover', 'pending', 'history', 'ready'));
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            echo implode(',', ActiveLoanMigration::Headers)."\n";
        }, 'active-loan-schedule.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request, LegacyLoanBalanceReport $reports, ActiveLoanMigration $migration): RedirectResponse
    {
        $request->session()->forget('active_loan_migration_preview');
        $data = $request->validate([
            'snapshot' => ['required', 'string', Rule::in($reports->snapshots())],
            'schedule' => ['required', 'file', 'max:2048'],
            'reference' => ['required', 'string', 'max:500'],
        ]);
        $cutover = DB::table('ledger_opening_journals')->value('effective_on');
        if ($cutover === null) {
            throw ValidationException::withMessages(['schedule' => 'Post the opening journal before migrating active loans.']);
        }
        $schedules = $migration->read($data['schedule'], $cutover);
        $request->session()->put('active_loan_migration_preview', [
            'actor' => $request->user()->id, 'token' => Str::random(40), 'cutover' => $cutover,
            'plan' => $migration->plan($data['snapshot'], $cutover, $schedules), 'schedules' => $schedules,
            'snapshot' => $data['snapshot'], 'reference' => $data['reference'],
            'sha256' => hash_file('sha256', $data['schedule']->getPathname()),
        ]);

        return redirect()->route('staff.active-loans.index')->withFragment('preview');
    }

    public function apply(Request $request, ActiveLoanMigration $migration): RedirectResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'size:40']])['token'];
        $preview = $this->current($request);
        if ($preview === null || ! hash_equals($preview['token'], $token)) {
            throw ValidationException::withMessages(['schedule' => 'Run a fresh migration preview first.']);
        }
        $count = $migration->apply($preview, $request->user()->id);
        $request->session()->forget('active_loan_migration_preview');

        return redirect()->route('staff.active-loans.index')->with('status', "$count active loans migrated.");
    }

    private function current(Request $request): ?array
    {
        $preview = $request->session()->get('active_loan_migration_preview');

        return ($preview['actor'] ?? null) === $request->user()->id ? $preview : null;
    }
}
