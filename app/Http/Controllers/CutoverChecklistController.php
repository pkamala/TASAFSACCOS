<?php

namespace App\Http\Controllers;

use App\TablePage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CutoverChecklistController extends Controller
{
    public function __invoke(Request $request): View
    {
        $data = $request->validate([
            'receivables' => ['sometimes', 'array', 'list', 'max:100'],
            'receivables.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $selected = array_map('intval', $data['receivables'] ?? []);
        $journal = DB::table('ledger_opening_journals')->first(['id', 'effective_on', 'review_reference']);
        if ($journal === null) {
            return view('staff.cutover-checklist', compact('journal'));
        }

        $asOf = CarbonImmutable::parse($journal->effective_on)->subDay()->toDateString();
        $opening = DB::table('ledger_opening_lines as line')->where('line.ledger_opening_journal_id', $journal->id)
            ->join('chart_accounts as account', 'account.id', '=', 'line.chart_account_id')
            ->orderBy('account.code')->orderBy('account.name')->orderBy('account.id')
            ->get(['account.id', 'account.code', 'account.name', 'line.signed_minor'])->keyBy('id');
        if (array_diff($selected, $opening->keys()->all()) !== []) {
            throw ValidationException::withMessages(['receivables' => 'Choose loan receivable accounts from the posted opening journal.']);
        }
        $receivableMinor = $selected === [] ? null : (int) $opening->only($selected)->sum('signed_minor');

        $openingMemberBalances = DB::table('member_verified_balances')->where('as_of', $asOf)
            ->selectRaw('DISTINCT ON (member_id) member_id, savings_minor, shares_minor')->orderBy('member_id')->orderByDesc('id');
        $members = DB::table('members as member')->leftJoinSub($openingMemberBalances, 'balance', 'balance.member_id', '=', 'member.id')
            ->selectRaw("count(*) FILTER (WHERE member.status = 'active') AS active_members,
                count(balance.member_id) FILTER (WHERE member.status = 'active') AS verified_active_members,
                count(balance.member_id) AS verified_members,
                coalesce(sum(balance.savings_minor), 0) AS savings_minor, coalesce(sum(balance.shares_minor), 0) AS shares_minor")
            ->first();

        $loans = DB::table('legacy_onboarding_rows as staged')->where('staged.source_table', 'loans')
            ->join('members as member', function (JoinClause $join): void {
                $join->on('member.legacy_source_sha256', '=', 'staged.source_sha256')
                    ->whereRaw("member.legacy_member_id::text = staged.data->>'member_id'");
            })
            ->leftJoin('verified_legacy_loan_balances as verified', function (JoinClause $join) use ($asOf): void {
                $join->on('verified.source_sha256', '=', 'staged.source_sha256')->on('verified.source_id', '=', 'staged.source_id')
                    ->on('verified.member_id', '=', 'member.id')->where('verified.as_of', $asOf);
            })
            ->leftJoin('active_loan_migrations as migration', 'migration.verified_balance_id', '=', 'verified.id')
            ->leftJoin('loan_applications as application', 'application.id', '=', 'migration.loan_application_id')
            ->whereRaw(<<<'SQL'
NOT coalesce(staged.data->>'disbursement_date' ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
    AND pg_input_is_valid(staged.data->>'disbursement_date', 'date')
    AND staged.data->>'disbursement_date' > ?, false)
SQL, [$asOf]);
        $totals = (clone $loans)->selectRaw('count(*) AS staged_loans,
            count(*) FILTER (WHERE verified.id IS NULL) AS unverified_loans,
            count(*) FILTER (WHERE verified.principal_minor > 0 AND migration.loan_application_id IS NULL) AS pending_loans,
            count(migration.loan_application_id) AS migrated_loans,
            coalesce(sum(verified.principal_minor), 0) AS verified_minor,
            coalesce(sum(application.principal_minor), 0) AS migrated_minor')
            ->first();
        $exceptions = $loans->where(fn ($query) => $query->whereNull('verified.id')
            ->orWhere(fn ($pending) => $pending->where('verified.principal_minor', '>', 0)->whereNull('migration.loan_application_id')))
            ->select('staged.source_id', 'member.id as member_id', 'member.registration_no', 'verified.principal_minor')
            ->selectRaw("staged.data->>'loan_code' AS reference")
            ->orderBy('member.registration_no')->orderBy('staged.source_id')
            ->simplePaginate(TablePage::size())->withQueryString();

        return view('staff.cutover-checklist', compact('journal', 'asOf', 'opening', 'selected', 'receivableMinor', 'members', 'totals', 'exceptions'));
    }
}
