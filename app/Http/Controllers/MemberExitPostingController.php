<?php

namespace App\Http\Controllers;

use App\PostMemberExitSettlement;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MemberExitPostingController extends Controller
{
    public function show(Request $request, int $exit, PostMemberExitSettlement $settlements): View
    {
        $record = DB::table('member_exits')->where('status', 'approved')->find($exit);
        abort_if($record === null, 404);
        $member = DB::table('members')->find($record->member_id);
        $today = CarbonImmutable::today('Africa/Dar_es_Salaam');
        $input = $request->validate(['effective_on' => $this->dateRules('nullable', $today)]);
        $effectiveOn = $input['effective_on'] ?? $today->toDateString();
        $plan = null;
        $fingerprint = null;
        $planIssue = null;
        try {
            $plan = $settlements->plan($member->id, CarbonImmutable::parse($effectiveOn, $today->timezone));
            $fingerprint = $settlements->fingerprint($plan);
        } catch (ValidationException $exception) {
            $planIssue = $exception->validator->errors()->first();
        }
        $accounts = DB::table('chart_accounts')->where('is_ledger_account', true)->whereNull('retired_at')
            ->orderBy('code')->orderBy('id')->get(['id', 'code', 'name']);
        $labels = PostMemberExitSettlement::Accounts;

        return view('staff.member-exits.post-settlement', compact('record', 'member', 'plan', 'fingerprint', 'accounts', 'labels', 'effectiveOn', 'today', 'planIssue'));
    }

    public function post(Request $request, int $exit, PostMemberExitSettlement $settlements): RedirectResponse
    {
        $today = CarbonImmutable::today('Africa/Dar_es_Salaam');
        $input = $request->validate([
            'effective_on' => $this->dateRules('required', $today),
            'fingerprint' => ['required', 'string', 'size:64'],
            'accounts' => ['nullable', Rule::array(array_keys(PostMemberExitSettlement::Accounts))],
            'accounts.*' => ['nullable', 'integer', 'min:1', 'distinct'],
            'legacy_clearance_reference' => ['nullable', 'string', 'max:500'],
        ]);

        $settlements->post(
            $exit, $request->user()->id, CarbonImmutable::parse($input['effective_on'], $today->timezone), $input['fingerprint'], $input['accounts'] ?? [], $input['legacy_clearance_reference'] ?? null,
        );

        return redirect()->route('staff.member-exits.voucher', $exit)->with('status', 'Exit settlement recorded.');
    }

    private function dateRules(string $presence, CarbonImmutable $today): array
    {
        return [$presence, 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$today->toDateString()];
    }
}
