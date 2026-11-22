<?php

namespace App\Http\Controllers;

use App\MemberFunds;
use App\TablePage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MemberAccountController extends Controller
{
    public function __invoke(Request $request, MemberFunds $funds): View
    {
        $id = $request->user()->member_id;
        abort_if($id === null, 403);

        $member = DB::table('members')->find($id, ['first_name', 'last_name', 'registration_no', 'status', 'email']);
        abort_if($member === null, 404);

        $loans = DB::table('loan_applications as application')->where('application.member_id', $id)
            ->leftJoin('loan_categories as category', 'category.id', '=', 'application.loan_category_id')
            ->orderByDesc('application.id')->simplePaginate(TablePage::size(), [
                'application.id', 'application.status', 'application.principal_minor', 'application.term_months', 'category.name as category_name',
            ]);

        $exit = DB::table('member_exits')->where('member_id', $id)->orderByDesc('id')->first(['status', 'requested_at', 'effective_on']);

        $withdrawal = DB::table('member_savings_withdrawals')->where('member_id', $id)->orderByDesc('id')
            ->first(['amount_minor', 'status']);
        $balances = $funds->balances($id);

        return view('member.account', compact('member', 'loans', 'exit', 'withdrawal', 'balances'));
    }
}
