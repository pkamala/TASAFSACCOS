<?php

namespace App\Http\Controllers;

use App\LoanApproval;
use App\Models\User;
use App\TablePage;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MemberProfileController extends Controller
{
    public function index(Request $request): View|string
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive,exited']]);
        $search = $input['q'] ?? '';
        $intake = $request->routeIs('staff.loan-intake');
        $status = $intake ? 'active' : ($input['status'] ?? '');
        $members = DB::table('members')
            ->select(['id', 'registration_no', 'first_name', 'last_name', 'status', 'joining_date'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($query) use ($term): void {
                    $query->whereLike('registration_no', $term)
                        ->orWhereLike('first_name', $term)
                        ->orWhereLike('last_name', $term);
                });
            })
            ->orderByRaw('lower(first_name), lower(last_name)')->orderBy('id')
            ->paginate(TablePage::size())->appends(['q' => $search, 'status' => $status]);

        if (! $intake) {
            $today = CarbonImmutable::today('Africa/Dar_es_Salaam');
            foreach ($members as $member) {
                $member->membership_months = null;
                if ($member->joining_date !== null) {
                    $joiningDate = CarbonImmutable::parse($member->joining_date, $today->timezone);
                    $months = ($today->year - $joiningDate->year) * 12 + $today->month - $joiningDate->month;
                    $member->membership_months = max(0, $months - (int) $today->lt($joiningDate->addMonthsNoOverflow($months)));
                }
            }
        }

        $view = view('staff.members', compact('members', 'search', 'status', 'intake'));

        return $request->ajax() ? $view->fragment('table-results') : $view;
    }

    public function create(): View
    {
        return view('staff.member-create', ['today' => now('Africa/Dar_es_Salaam')->toDateString()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'registration_no' => is_string($request->input('registration_no')) ? Str::upper(trim($request->input('registration_no'))) : $request->input('registration_no'),
            'email' => is_string($request->input('email')) ? Str::lower(trim($request->input('email'))) : $request->input('email'),
        ]);
        $input = $request->validate([
            'registration_no' => ['required', 'string', 'max:30', 'regex:/\A[A-Z0-9-]+\z/', 'unique:members'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:members'],
            'phone' => ['required', 'regex:/\A\+?[0-9]{10,15}\z/'],
            'joining_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
        ]);

        try {
            $member = DB::transaction(function () use ($request, $input): int {
                $now = now();
                $id = DB::table('members')->insertGetId([...$input, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
                DB::table('member_audits')->insert([
                    'member_id' => $id, 'actor_id' => $request->user()->id, 'recorded_at' => $now,
                    'changes' => json_encode(['membership' => ['before' => null, 'after' => 'Member registered']], JSON_THROW_ON_ERROR),
                ]);

                return $id;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['registration_no' => 'This membership number or email is already registered.']);
        }

        return redirect()->route('staff.members.show', $member)->with('status', 'Member registered.');
    }

    public function show(int $member): Response
    {
        $profile = DB::table('members')->find($member);
        abort_if(! $profile, 404);

        $history = DB::table('member_audits')
            ->join('users', 'users.id', '=', 'member_audits.actor_id')
            ->where('member_audits.member_id', $member)->orderByDesc('member_audits.id')
            ->select('member_audits.*', 'users.name as actor')
            ->tap(TablePage::search(['member_audits.recorded_at', 'member_audits.changes', 'users.name']))
            ->paginate(TablePage::size());

        $login = User::query()->where('member_id', $member)->first(['email', 'is_staff_admin', 'local_setup_pending', 'loan_workflow_roles']);

        $exitedOn = DB::table('member_exits')->where('member_id', $member)->where('status', 'finalized')->value('effective_on');

        $previousMembership = $profile->previous_membership_id === null
            ? null : DB::table('members')->find($profile->previous_membership_id, ['id', 'registration_no']);
        $nextMembership = $profile->status === 'exited'
            ? DB::table('members')->where('previous_membership_id', $member)->first(['id', 'registration_no']) : null;
        $today = now('Africa/Dar_es_Salaam')->toDateString();

        return response()->view('staff.member', compact('profile', 'history', 'login', 'exitedOn', 'previousMembership', 'nextMembership', 'today'), headers: [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function rejoin(Request $request, int $member): RedirectResponse
    {
        $input = $request->validate([
            'rejoin_joining_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
        ]);

        try {
            $newMember = DB::transaction(function () use ($request, $member, $input): int {
                DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['board-roles']);
                DB::statement('LOCK TABLE members IN SHARE ROW EXCLUSIVE MODE');
                $profile = DB::table('members')->lockForUpdate()->find($member);
                abort_if($profile === null, 404);

                if ($profile->status !== 'exited' || DB::table('members')->where('previous_membership_id', $member)->exists()) {
                    throw ValidationException::withMessages(['rejoin' => 'Rejoining requires an exited membership that has yet to rejoin.']);
                }

                $exitedOn = DB::table('member_exits')->where('member_id', $member)->where('status', 'finalized')->max('effective_on');
                $joiningDate = $input['rejoin_joining_date'];
                if (($exitedOn !== null && $joiningDate < $exitedOn) || ($profile->joining_date !== null && $joiningDate <= $profile->joining_date)) {
                    throw ValidationException::withMessages(['rejoin_joining_date' => 'Choose a date after the previous joining date and on or after the exit date.']);
                }

                $login = User::query()->where('member_id', $member)->lockForUpdate()->first();
                $now = now();
                $registrationNo = DB::table('members')
                    ->whereRaw("registration_no ~ '^[0-9]+$'")
                    ->selectRaw('CAST(COALESCE(MAX(CAST(registration_no AS numeric)), 0) + 1 AS text) AS next_number')
                    ->first()->next_number;
                if (strlen($registrationNo) > 30) {
                    throw ValidationException::withMessages(['rejoin' => 'No higher member number fits the 30-digit limit.']);
                }
                $id = DB::table('members')->insertGetId([
                    'registration_no' => $registrationNo,
                    'previous_membership_id' => $member,
                    'first_name' => $profile->first_name,
                    'middle_name' => $profile->middle_name,
                    'last_name' => $profile->last_name,
                    'email' => $profile->email,
                    'phone' => $profile->phone,
                    'joining_date' => $joiningDate,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($login !== null && ! $login->is_staff_admin) {
                    $login->member_id = $id;
                    $login->loan_workflow_roles = [];
                    $login->board_role = null;
                    $login->save();
                }

                DB::table('member_audits')->insert([
                    [
                        'member_id' => $member, 'actor_id' => $request->user()->id, 'recorded_at' => $now,
                        'changes' => json_encode(['next_membership' => ['before' => null, 'after' => $registrationNo]], JSON_THROW_ON_ERROR),
                    ],
                    [
                        'member_id' => $id, 'actor_id' => $request->user()->id, 'recorded_at' => $now,
                        'changes' => json_encode(['previous_membership' => ['before' => null, 'after' => $profile->registration_no]], JSON_THROW_ON_ERROR),
                    ],
                ]);

                return $id;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['rejoin' => 'The member email is already in use by another membership.']);
        }

        return redirect()->route('staff.members.show', $newMember)->with('status', 'New membership registered.');
    }

    public function update(Request $request, int $member): RedirectResponse
    {
        $input = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['present', 'nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['present', 'nullable', 'regex:/\A\+?[0-9]{10,15}\z/'],
            'joining_date' => ['present', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
        ]);

        $this->saveChanges($member, $request->user()->id, $input);

        return redirect()->route('staff.members.show', $member)->with('status', 'Member profile saved.');
    }

    public function updateStatus(Request $request, int $member): RedirectResponse
    {
        $data = $request->validate(['membership_status' => ['required', 'in:active,inactive'], 'status_reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($request, $member, $data): void {
            $profile = DB::table('members')->lockForUpdate()->find($member);
            abort_if($profile === null, 404);
            if ($profile->status === 'exited' || DB::table('member_exits')->where('member_id', $member)
                ->whereIn('status', ['requested', 'approved'])->exists()) {
                throw ValidationException::withMessages(['membership_status' => 'Use the membership exit workflow for a member with an exit request or completed exit.']);
            }
            $this->saveChanges($member, $request->user()->id, ['status' => $data['membership_status']], $data['status_reason'], 'status_reason');
        }, 3);

        return redirect()->route('staff.members.show', $member)->with('status', 'Membership status saved.');
    }

    public function updateLoanRole(Request $request, int $member): RedirectResponse
    {
        $data = $request->validate([
            'loan_workflow_roles' => ['sometimes', 'array', 'list', 'max:3'],
            'loan_workflow_roles.*' => ['required', 'string', 'distinct', Rule::in(array_keys(LoanApproval::Roles))],
            'role_reason' => ['required', 'string', 'max:500'],
        ]);
        $roles = array_values(array_intersect(array_keys(LoanApproval::Roles), $data['loan_workflow_roles'] ?? []));
        DB::transaction(function () use ($request, $member, $data, $roles): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['board-roles']);
            $profile = DB::table('members')->lockForUpdate()->find($member);
            abort_if($profile === null, 404);
            $login = User::query()->where('member_id', $member)->where('is_staff_admin', false)->lockForUpdate()->first();
            if ($login === null || ($profile->status !== 'active' && $roles !== [])) {
                throw ValidationException::withMessages(['loan_workflow_roles' => 'Assign loan roles to active members with a regular member login.']);
            }
            $previous = $login->loan_workflow_roles;
            if ($previous === $roles) {
                return;
            }
            $login->loan_workflow_roles = $roles;
            $login->save();
            DB::table('member_audits')->insert([
                'member_id' => $member, 'actor_id' => $request->user()->id, 'recorded_at' => now(),
                'changes' => json_encode([
                    'loan_workflow_roles' => ['before' => $previous, 'after' => $roles],
                    'role_reason' => ['before' => null, 'after' => $data['role_reason']],
                ], JSON_THROW_ON_ERROR),
            ]);
        }, 3);

        return redirect()->route('staff.members.show', $member)->with('status', 'Loan workflow roles saved.');
    }

    public function updatePayroll(Request $request, int $member): RedirectResponse
    {
        $input = $request->validate([
            'payroll_savings' => ['required', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
            'payroll_shares' => ['required', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
            'payroll_reference' => ['required', 'string', 'max:500'],
        ]);
        $minor = static function (string $amount): int {
            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

            return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        };
        $this->saveChanges($member, $request->user()->id, [
            'payroll_savings_minor' => $minor($input['payroll_savings']),
            'payroll_shares_minor' => $minor($input['payroll_shares']),
        ], $input['payroll_reference']);

        return redirect()->route('staff.members.show', $member)->with('status', 'Payroll contributions saved.');
    }

    private function saveChanges(int $member, int $actor, array $input, ?string $reference = null, string $referenceField = 'payroll_reference'): void
    {
        DB::transaction(function () use ($member, $actor, $input, $reference, $referenceField): void {
            $profile = DB::table('members')->lockForUpdate()->find($member);
            abort_if(! $profile, 404);

            $changes = [];
            foreach ($input as $field => $value) {
                if ($value === $profile->$field) {
                    continue;
                }
                if ($referenceField === 'payroll_reference' && $reference !== null) {
                    $label = str_replace('_minor', '_TZS', $field);
                    $changes[$label] = [
                        'before' => number_format($profile->$field / 100, 2, '.', ''),
                        'after' => number_format($value / 100, 2, '.', ''),
                    ];
                } else {
                    $changes[$field] = ['before' => $profile->$field, 'after' => $value];
                }
            }

            if ($changes === []) {
                return;
            }
            if ($reference !== null) {
                $changes[$referenceField] = ['before' => null, 'after' => $reference];
            }

            $now = now();
            DB::table('members')->where('id', $member)->update([...$input, 'updated_at' => $now]);
            DB::table('member_audits')->insert([
                'member_id' => $member,
                'actor_id' => $actor,
                'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
                'recorded_at' => $now,
            ]);
        }, 3);
    }
}
