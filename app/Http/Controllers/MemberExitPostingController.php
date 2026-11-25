<?php

namespace App\Http\Controllers;

use App\PostMemberExitSettlement;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MemberExitPostingController extends Controller
{
    public function show(int $exit, PostMemberExitSettlement $settlements): View
    {
        $record = DB::table('member_exits')->where('status', 'approved')->find($exit);
        abort_if($record === null, 404);
        $member = DB::table('members')->find($record->member_id);
        $plan = $settlements->plan($member->id, CarbonImmutable::today('Africa/Dar_es_Salaam'));
        $fingerprint = $settlements->fingerprint($plan);
        $accounts = DB::table('chart_accounts')->where('is_ledger_account', true)->whereNull('retired_at')
            ->orderBy('code')->orderBy('id')->get(['id', 'code', 'name']);
        $labels = PostMemberExitSettlement::Accounts;

        return view('staff.member-exits.post-settlement', compact('record', 'member', 'plan', 'fingerprint', 'accounts', 'labels'));
    }

    public function post(Request $request, int $exit, PostMemberExitSettlement $settlements): RedirectResponse
    {
        $input = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'accounts' => ['nullable', Rule::array(array_keys(PostMemberExitSettlement::Accounts))],
            'accounts.*' => ['nullable', 'integer', 'min:1', 'distinct'],
            'legacy_clearance_reference' => ['nullable', 'string', 'max:500'],
        ]);

        $settlements->post(
            $exit, $request->user()->id, $input['fingerprint'], $input['accounts'] ?? [], $input['legacy_clearance_reference'] ?? null,
        );

        return redirect()->route('staff.member-exits.voucher', $exit)->with('status', 'Exit settlement recorded.');
    }
}
