<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MemberProfileController extends Controller
{
    public function index(Request $request): View
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
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->simplePaginate(25)->appends(['q' => $search]);

        return view('staff.members', compact('members', 'search'));
    }

    public function show(int $member): View
    {
        $profile = DB::table('members')->find($member);
        abort_if(! $profile, 404);

        $history = DB::table('member_audits')
            ->join('users', 'users.id', '=', 'member_audits.actor_id')
            ->where('member_audits.member_id', $member)->orderByDesc('member_audits.id')
            ->select('member_audits.*', 'users.name as actor')
            ->simplePaginate(20);

        return view('staff.member', compact('profile', 'history'));
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

        DB::transaction(function () use ($request, $member, $input): void {
            $profile = DB::table('members')->lockForUpdate()->find($member);
            abort_if(! $profile, 404);

            $changes = [];
            foreach ($input as $field => $value) {
                if ($value !== $profile->$field) {
                    $changes[$field] = ['before' => $profile->$field, 'after' => $value];
                }
            }

            if ($changes === []) {
                return;
            }

            $now = now();
            DB::table('members')->where('id', $member)->update([...$input, 'updated_at' => $now]);
            DB::table('member_audits')->insert([
                'member_id' => $member,
                'actor_id' => $request->user()->id,
                'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
                'recorded_at' => $now,
            ]);
        }, 3);

        return redirect()->route('staff.members.show', $member)->with('status', 'Member profile saved.');
    }
}
