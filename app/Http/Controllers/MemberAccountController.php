<?php

namespace App\Http\Controllers;

use App\LoanApproval;
use App\LoanSponsorship;
use App\MemberFunds;
use App\TablePage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MemberAccountController extends Controller
{
    public function __invoke(Request $request, MemberFunds $funds, LoanSponsorship $sponsorship): View
    {
        $id = $request->user()->member_id;
        abort_if($id === null, 403);

        $member = DB::table('members')->find($id, ['first_name', 'last_name', 'registration_no', 'status', 'email', 'joining_date']);
        abort_if($member === null, 404);

        $loans = DB::table('loan_applications as application')->where('application.member_id', $id)
            ->leftJoin('loan_categories as category', 'category.id', '=', 'application.loan_category_id')
            ->orderByDesc('application.id')->select([
                'application.id', 'application.status', 'application.approval_lane', 'application.principal_minor', 'application.term_months', 'application.interest_method', 'category.name as category_name',
            ])->addSelect(['confirmed_sponsors' => $sponsorship->confirmedActiveCount('application')])->simplePaginate(TablePage::size());

        $exit = DB::table('member_exits')->where('member_id', $id)->orderByDesc('id')->first(['status', 'requested_at', 'effective_on']);

        $withdrawalQuery = DB::table('member_savings_withdrawals')->where('member_id', $id)->orderByDesc('id');
        $withdrawal = (clone $withdrawalQuery)->first(['status']);
        $withdrawals = $withdrawalQuery->simplePaginate(
            TablePage::size('withdrawals_page'), ['id', 'amount_minor', 'status', 'requested_at'], 'withdrawals_page',
        );
        $balances = $funds->balances($id);

        $sponsorships = DB::table('loan_sponsorships as sponsorship')
            ->where('sponsorship.member_id', $id)
            ->join('loan_applications as loan', 'loan.id', '=', 'sponsorship.loan_application_id')
            ->join('members as borrower', 'borrower.id', '=', 'loan.member_id')
            ->where('loan.status', 'submitted')
            ->where(fn ($query) => $query->whereIn('loan.approval_lane', LoanApproval::PendingLanes)
                ->orWhereNull('loan.approval_lane')->orWhereNotNull('sponsorship.confirmed_at'))
            ->orderByDesc('sponsorship.id')->simplePaginate(TablePage::size('sponsorships_page'), [
                'sponsorship.id', 'sponsorship.confirmed_at', 'loan.id as application',
                'loan.term_months', 'borrower.first_name', 'borrower.last_name', 'borrower.registration_no',
                DB::raw('loan.principal_minor + loan.insurance_minor + loan.processing_fee_minor + loan.other_charges_minor as financed_minor'),
            ], 'sponsorships_page')->withQueryString();

        return view('member.account', compact('member', 'loans', 'exit', 'withdrawal', 'withdrawals', 'balances', 'sponsorships'));
    }
}
