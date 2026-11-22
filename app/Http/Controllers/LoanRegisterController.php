<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoanRegisterController extends Controller
{
    public function __invoke(Request $request): View
    {
        $source = $request->validate(['source' => ['nullable', 'in:applications,legacy']])['source'] ?? 'applications';

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
            $loans = $query->when($status !== null, fn ($query) => $query->whereRaw("loan.data->>'loan_status' = ?", [$status]))
                ->select('loan.source_id as id', 'member.id as member_id', 'member.registration_no', 'member.first_name', 'member.last_name')
                ->selectRaw("loan.data->>'loan_code' AS reference, loan.data->>'loan_status' AS status, loan.data->>'principle' AS principal, loan.data->>'duration' AS term_months")
                ->orderBy('loan.source_id')->get();
        } else {
            $statuses = ['submitted', 'approved', 'declined', 'disbursed', 'repaid', 'cancelled'];
            $status = $request->validate(['status' => ['nullable', Rule::in($statuses)]])['status'] ?? null;
            $loans = DB::table('loan_applications as loan')
                ->join('members as member', 'member.id', '=', 'loan.member_id')
                ->when($status !== null, fn ($query) => $query->where('loan.status', $status))
                ->select([
                    'loan.id', 'loan.member_id', 'loan.status', 'loan.term_months', 'loan.interest_method', 'loan.first_due_date',
                    'member.registration_no', 'member.first_name', 'member.last_name',
                ])
                ->selectRaw('loan.principal_minor::numeric / 100 AS principal, loan.annual_basis_points::numeric / 100 AS rate')
                ->selectRaw('(loan.insurance_minor + loan.processing_fee_minor + loan.other_charges_minor)::numeric / 100 AS charges')
                ->orderBy('loan.id')->get();
        }

        $printedOn = now('Africa/Dar_es_Salaam')->format('d M Y H:i');

        return view('staff.loan-applications.register', compact('source', 'statuses', 'status', 'loans', 'printedOn'));
    }
}
