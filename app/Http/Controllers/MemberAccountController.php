<?php

namespace App\Http\Controllers;

use App\MemberFunds;
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

        $loans = DB::table('loan_applications')->where('member_id', $id)->orderByDesc('id')
            ->simplePaginate(15, ['id', 'status', 'principal_minor', 'term_months']);

        $exit = DB::table('member_exits')->where('member_id', $id)->orderByDesc('id')->first(['status', 'requested_at', 'effective_on']);

        $withdrawal = DB::table('member_savings_withdrawals')->where('member_id', $id)->orderByDesc('id')
            ->first(['amount_minor', 'status']);
        $balances = $funds->balances($id);

        return view('member.account', compact('member', 'loans', 'exit', 'withdrawal', 'balances'));
    }
}
