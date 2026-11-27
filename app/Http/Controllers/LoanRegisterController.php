<?php

namespace App\Http\Controllers;

use App\TablePage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoanRegisterController extends Controller
{
    public function __invoke(Request $request): View
    {
        $dates = $request->validate([
            'source' => ['nullable', 'in:applications,legacy'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $source = $dates['source'] ?? 'applications';
        $from = $dates['from'] ?? null;
        $to = $dates['to'] ?? null;

        $categories = collect();
        $categoryId = null;
        if ($source === 'legacy') {
            abort_unless($request->user()->can('manage-members'), 403);
            $query = DB::table('legacy_onboarding_rows as loan')->where('loan.source_table', 'loans')
                ->join('members as member', function ($join): void {
                    $join->on('member.legacy_source_sha256', '=', 'loan.source_sha256')
                        ->whereRaw("member.legacy_member_id::text = loan.data->>'member_id'");
                });
            $statuses = (clone $query)->whereRaw("nullif(loan.data->>'loan_status', '') IS NOT NULL")
                ->selectRaw("distinct loan.data->>'loan_status' AS status")->orderBy('status')->pluck('status')->all();
            $status = $request->validate(['status' => ['nullable', Rule::in($statuses)]])['status'] ?? null;
            $loanDate = "CASE WHEN pg_input_is_valid(loan.data->>'disbursement_date', 'date')
                    THEN (loan.data->>'disbursement_date')::date END";
            $loans = $query->when($from, fn ($query) => $query->whereRaw("({$loanDate}) >= ?", [$from]))
                ->when($to, fn ($query) => $query->whereRaw("({$loanDate}) <= ?", [$to]))
                ->tap(TablePage::memberStatus('member.status'))
                ->when($status !== null, fn ($query) => $query->whereRaw("loan.data->>'loan_status' = ?", [$status]))
                ->select('loan.source_id as id', 'member.id as member_id', 'member.registration_no', 'member.first_name', 'member.last_name')
                ->selectRaw("({$loanDate}) AS loan_date")
                ->selectRaw("loan.data->>'loan_code' AS reference, loan.data->>'loan_status' AS status, loan.data->>'principle' AS principal, loan.data->>'duration' AS term_months")
                ->orderByRaw('lower(member.first_name), lower(member.last_name)')->orderBy('loan.source_id')->get();
        } else {
            $statuses = ['submitted', 'approved', 'declined', 'disbursed', 'repaid', 'cancelled'];
            $status = $request->validate(['status' => ['nullable', Rule::in($statuses)]])['status'] ?? null;
            $categoryId = (int) ($request->validate(['category' => ['nullable', 'integer', 'exists:loan_categories,id']])['category'] ?? 0);
            $categories = DB::table('loan_categories')->orderBy('name')->get(['id', 'name']);
            $loanDate = "coalesce(transfer.transferred_on, (loan.created_at AT TIME ZONE 'Africa/Dar_es_Salaam')::date)";
            $loans = DB::table('loan_applications as loan')
                ->leftJoinLateral(DB::table('loan_disbursements')->whereColumn('loan_application_id', 'loan.id')
                    ->orderBy('transferred_on')->limit(1)->select('transferred_on'), 'transfer')
                ->when($from, fn ($query) => $query->whereRaw("{$loanDate} >= ?", [$from]))
                ->when($to, fn ($query) => $query->whereRaw("{$loanDate} <= ?", [$to]))
                ->leftJoin('loan_categories as category', 'category.id', '=', 'loan.loan_category_id')
                ->when($categoryId, fn ($query) => $query->where('loan.loan_category_id', $categoryId))
                ->join('members as member', 'member.id', '=', 'loan.member_id')
                ->tap(TablePage::memberStatus('member.status'))
                ->when($status !== null, fn ($query) => $query->where('loan.status', $status))
                ->select([
                    'loan.id', 'loan.member_id', 'loan.status', 'loan.term_months', 'loan.interest_method', 'loan.first_due_date',
                    'member.registration_no', 'member.first_name', 'member.last_name', 'category.name as category_name',
                ])
                ->selectRaw("{$loanDate} as loan_date")
                ->selectRaw('loan.principal_minor::numeric / 100 AS principal, loan.annual_basis_points::numeric / 100 AS rate')
                ->selectRaw('(loan.insurance_minor + loan.processing_fee_minor + loan.other_charges_minor)::numeric / 100 AS charges')
                ->orderByRaw('lower(member.first_name), lower(member.last_name)')->orderBy('loan.id')->get();
        }

        $printedOn = now('Africa/Dar_es_Salaam')->format('d M Y H:i');

        return view('staff.loan-applications.register', compact('source', 'statuses', 'status', 'loans', 'printedOn', 'categories', 'categoryId', 'from', 'to'));
    }
}
