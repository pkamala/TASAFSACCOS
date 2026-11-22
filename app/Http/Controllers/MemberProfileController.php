<?php

namespace App\Http\Controllers;

use App\TablePage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MemberProfileController extends Controller
{
    public function index(Request $request): View|string
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = $input['q'] ?? '';
        $members = DB::table('members')
            ->select(['id', 'registration_no', 'first_name', 'last_name', 'status'])
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($query) use ($term): void {
                    $query->whereLike('registration_no', $term)
                        ->orWhereLike('first_name', $term)
                        ->orWhereLike('last_name', $term);
                });
            })
            ->orderByRaw('lower(first_name), lower(last_name)')->orderBy('id')
            ->simplePaginate(TablePage::size())->appends(['q' => $search]);

        $view = view('staff.members', compact('members', 'search'));

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
            ->simplePaginate(TablePage::size());

        $login = DB::table('users')->where('member_id', $member)->first(['email', 'is_staff_admin', 'local_setup_pending']);

        return response()->view('staff.member', compact('profile', 'history', 'login'), headers: [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
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

    private function saveChanges(int $member, int $actor, array $input, ?string $reference = null): void
    {
        DB::transaction(function () use ($member, $actor, $input, $reference): void {
            $profile = DB::table('members')->lockForUpdate()->find($member);
            abort_if(! $profile, 404);

            $changes = [];
            foreach ($input as $field => $value) {
                if ($value === $profile->$field) {
                    continue;
                }
                if ($reference !== null) {
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
                $changes['payroll_reference'] = ['before' => null, 'after' => $reference];
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
