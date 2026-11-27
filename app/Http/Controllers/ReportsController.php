<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportsController extends Controller
{
    public function __invoke(): View
    {
        $portfolio = DB::table('loan_applications as loan')
            ->leftJoin('loan_categories as category', 'category.id', '=', 'loan.loan_category_id')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('loan_disbursements as transfer')
                ->whereColumn('transfer.loan_application_id', 'loan.id'))
            ->select('category.name as category')
            ->selectRaw('count(*) as disbursed_count')
            ->selectRaw("count(*) filter (where loan.status = 'disbursed') as active_count")
            ->selectRaw('sum(loan.principal_minor + loan.insurance_minor + loan.processing_fee_minor + loan.other_charges_minor) as financed_minor')
            ->groupBy('category.name')->orderBy('category.name')->get();

        $payroll = DB::table('payroll_loan_reconciliations')
            ->select('payroll_month')
            ->selectRaw('count(*) filter (where receipt_id is not null) as reconciled_count')
            ->selectRaw('count(*) filter (where receipt_id is null) as pending_count')
            ->selectRaw('coalesce(sum(total_minor) filter (where receipt_id is not null), 0) as reconciled_minor')
            ->selectRaw('coalesce(sum(total_minor) filter (where receipt_id is null), 0) as pending_minor')
            ->groupBy('payroll_month')->orderByDesc('payroll_month')->get();

        return view('staff.reports', compact('portfolio', 'payroll'));
    }
}
