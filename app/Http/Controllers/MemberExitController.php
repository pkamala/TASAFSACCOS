<?php

namespace App\Http\Controllers;

use App\MemberExitSettlement;
use App\Models\User;
use App\TablePage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MemberExitController extends Controller
{
    public function index(): View
    {
        $exits = DB::table('member_exits as exit')->join('members as member', 'member.id', '=', 'exit.member_id')
            ->orderByDesc('exit.id')
            ->tap(TablePage::memberStatus('member.status'))
            ->tap(TablePage::search(['exit.id', 'exit.status', 'exit.requested_at', 'member.registration_no', 'member.first_name', 'member.last_name']))
            ->paginate(TablePage::size(), [
                'exit.id', 'exit.status', 'exit.requested_at', 'member.registration_no', 'member.first_name', 'member.last_name',
            ]);

        return view('staff.member-exits.index', compact('exits'));
    }

    public function create(int $member): View
    {
        $profile = DB::table('members')->find($member);
        abort_if($profile === null, 404);

        $latest = DB::table('member_exits')->where('member_id', $member)->orderByDesc('id')->first();

        return view('staff.member-exits.create', compact('profile', 'latest'));
    }

    public function store(Request $request, int $member): RedirectResponse
    {
        $exit = $this->requestExit($request, $member);

        return redirect()->route('staff.member-exits.show', $exit);
    }

    public function storeOwn(Request $request): RedirectResponse
    {
        $member = $request->user()->member_id;
        abort_if($member === null, 403);
        $this->requestExit($request, $member);

        return redirect()->route('member.account')->with('status', 'Exit request submitted.');
    }

    public function show(Request $request, int $exit, MemberExitSettlement $settlements): View
    {
        $record = DB::table('member_exits')->find($exit);
        abort_if($record === null, 404);
        $profile = DB::table('members')->find($record->member_id);
        $actors = DB::table('users')->whereIn('id', array_filter([$record->requested_by, $record->reviewed_by, $record->finalized_by]))->pluck('name', 'id');
        $openLoans = $this->openLoans($record->member_id)->count();

        $today = CarbonImmutable::today('Africa/Dar_es_Salaam');
        $input = $request->validate(['effective_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$today->toDateString()]]);
        $draftDate = CarbonImmutable::parse($input['effective_on'] ?? $today->toDateString(), $today->timezone);
        $draft = in_array($record->status, ['requested', 'approved'], true) ? $settlements->draft($profile->id, $draftDate) : null;

        return view('staff.member-exits.show', compact('record', 'profile', 'actors', 'openLoans', 'draft', 'draftDate', 'today'));
    }

    public function voucher(int $exit, MemberExitSettlement $settlements): View
    {
        $record = DB::table('member_exits')->find($exit);
        abort_if($record === null || ! in_array($record->status, ['approved', 'finalized'], true), 404);
        $profile = DB::table('members')->find($record->member_id);
        $snapshot = $record->status === 'finalized'
            ? json_decode($record->voucher_snapshot ?? 'null', true, flags: JSON_THROW_ON_ERROR)
            : $this->voucherSnapshot($record->member_id, $settlements);

        return view('staff.member-exits.voucher', compact('record', 'profile', 'snapshot'));
    }

    public function review(Request $request, int $exit): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,declined'],
            'review_note' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $exit, $data): void {
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['board-roles']);
            $actor = User::query()->findOrFail($request->user()->id);
            $record = DB::table('member_exits')->lockForUpdate()->find($exit);
            abort_if($record === null, 404);
            $borrowerRole = DB::table('users')->where('member_id', $record->member_id)->value('board_role');
            abort_unless($actor->isBoardOfficer() && $actor->member_id !== $record->member_id && $actor->id !== $record->requested_by
                && ! in_array($borrowerRole, [$actor->board_role], true), 403);
            if ($record->status !== 'requested') {
                $this->invalid('Only a requested exit can be reviewed.');
            }
            DB::table('member_exits')->where('id', $exit)->update([
                'status' => $data['decision'], 'reviewed_by' => $actor->id,
                'reviewed_at' => now(), 'review_note' => $data['review_note'],
            ]);
        }, 3);

        return back()->with('status', 'Exit decision recorded.');
    }

    public function finalize(Request $request, int $exit, MemberExitSettlement $settlements): RedirectResponse
    {
        $data = $request->validate([
            'effective_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'settlement_reference' => ['required', 'string', 'max:500'],
            'legacy_clearance_reference' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $exit, $data, $settlements): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['board-roles']);
            $initial = DB::table('member_exits')->find($exit);
            abort_if($initial === null, 404);
            $profile = DB::table('members')->lockForUpdate()->find($initial->member_id);
            $record = DB::table('member_exits')->lockForUpdate()->find($exit);
            if ($record->status !== 'approved' || ! in_array($profile->status, ['active', 'inactive', 'deceased'], true)) {
                $this->invalid('An approved exit for an unsettled membership is required.');
            }
            if ($profile->joining_date !== null && $data['effective_on'] < $profile->joining_date) {
                $this->invalid('The effective date cannot precede the joining date.');
            }
            if ($profile->legacy_member_id !== null && empty($data['legacy_clearance_reference'])) {
                $this->invalid('Record the legacy balance and liabilities clearance reference.');
            }
            if ($this->openLoans($profile->id)->exists()) {
                $this->invalid('Resolve the open operational loans and applications before finalizing.');
            }

            if (DB::table('member_savings_withdrawals')->where('member_id', $profile->id)
                ->whereIn('status', ['requested', 'approved'])->exists()) {
                $this->invalid('Resolve open savings withdrawal requests before finalizing the exit.');
            }

            $now = now();
            DB::table('member_exits')->where('id', $exit)->update([
                'status' => 'finalized', 'finalized_by' => $request->user()->id, 'finalized_at' => $now,
                'voucher_snapshot' => ($snapshot = $this->voucherSnapshot($profile->id, $settlements, CarbonImmutable::parse($data['effective_on'], 'Africa/Dar_es_Salaam'))) === null
                    ? null : json_encode($snapshot, JSON_THROW_ON_ERROR),
                ...$data,
            ]);
            $settlements->deactivateMember($profile->id, $exit, $request->user()->id);
        }, 3);

        return back()->with('status', 'Exit finalized and membership deactivated.');
    }

    private function voucherSnapshot(int $member, MemberExitSettlement $settlements, ?CarbonImmutable $date = null): ?array
    {
        $date ??= CarbonImmutable::today('Africa/Dar_es_Salaam');
        $amounts = $settlements->draft($member, $date);

        return $amounts === null ? null : ['calculated_on' => $date->toDateString(), 'amounts' => $amounts];
    }

    private function requestExit(Request $request, int $member): int
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return DB::transaction(function () use ($request, $member, $data): int {
            $profile = DB::table('members')->lockForUpdate()->find($member);
            abort_if($profile === null, 404);
            $allowedStatuses = $request->routeIs('staff.member-exits.store') ? ['active', 'inactive', 'deceased'] : ['active'];
            if (! in_array($profile->status, $allowedStatuses, true) || DB::table('member_exits')->where('member_id', $member)
                ->whereIn('status', ['requested', 'approved', 'finalized'])->exists()) {
                $this->invalid('The membership must be eligible for settlement and have no open or finalized exit request.');
            }

            return DB::table('member_exits')->insertGetId([
                'member_id' => $member, 'status' => 'requested', 'requested_by' => $request->user()->id,
                'requested_at' => now(), 'reason' => $data['reason'],
            ]);
        }, 3);
    }

    private function openLoans(int $member): Builder
    {
        return DB::table('loan_applications')->where('member_id', $member)->whereIn('status', ['submitted', 'approved', 'disbursed']);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['exit' => $message]);
    }
}
