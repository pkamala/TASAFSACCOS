<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BoardRoleController extends Controller
{
    private const Roles = ['chairperson' => 'Chairperson', 'secretary' => 'Secretary'];

    public function index(): View
    {
        $holders = DB::table('users')->leftJoin('members', 'members.id', '=', 'users.member_id')
            ->whereIn('users.board_role', array_keys(self::Roles))
            ->get(['users.id', 'users.name', 'users.board_role', 'members.registration_no'])->keyBy('board_role');
        $history = DB::table('board_role_changes as change')
            ->leftJoin('users as previous', 'previous.id', '=', 'change.previous_user_id')
            ->leftJoin('users as next', 'next.id', '=', 'change.next_user_id')
            ->join('users as actor', 'actor.id', '=', 'change.changed_by')
            ->orderByDesc('change.id')
            ->simplePaginate(25, ['change.*', 'previous.name as previous_name', 'next.name as next_name', 'actor.name as actor_name']);

        return view('staff.board-roles', ['roles' => self::Roles, ...compact('holders', 'history')]);
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        abort_unless(isset(self::Roles[$role]), 404);

        $data = $request->validate([
            'operation' => ['required', 'in:assign,vacate'],
            'current_holder' => ['required', 'integer', 'min:0'],
            'registration_no' => ['required_if:operation,assign', 'nullable', 'string', 'max:30'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $changed = DB::transaction(function () use ($request, $data, $role): bool {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['board-roles']);
            $previous = DB::table('users')->where('board_role', $role)->lockForUpdate()->first();
            if ((int) $data['current_holder'] !== ($previous->id ?? 0)) {
                throw ValidationException::withMessages(['current_holder' => 'The role assignment changed. Review the current officeholder and try again.']);
            }

            $next = null;
            if ($data['operation'] === 'assign') {
                $number = Str::upper(trim($data['registration_no']));
                $next = DB::table('users')->join('members', 'members.id', '=', 'users.member_id')
                    ->where('members.registration_no', $number)->where('members.status', 'active')
                    ->where('users.is_staff_admin', false)
                    ->first(['users.id', 'users.board_role']);

                if (! $next || ($next->board_role !== null && $next->board_role !== $role)) {
                    throw ValidationException::withMessages([
                        'registration_no' => 'Choose an active member with a member account and no other board role.',
                    ]);
                }
            }

            if (($previous->id ?? null) === ($next->id ?? null)) {
                return false;
            }

            $now = now();
            if ($previous) {
                DB::table('users')->where('id', $previous->id)->update(['board_role' => null, 'updated_at' => $now]);
            }
            if ($next) {
                DB::table('users')->where('id', $next->id)->update(['board_role' => $role, 'updated_at' => $now]);
            }

            DB::table('board_role_changes')->insert([
                'role' => $role,
                'previous_user_id' => $previous->id ?? null,
                'next_user_id' => $next->id ?? null,
                'changed_by' => $request->user()->id,
                'reason' => $data['reason'],
                'changed_at' => $now,
            ]);

            return true;
        }, 3);

        return redirect()->route('staff.board-roles.index')
            ->with('status', $changed ? self::Roles[$role].' assignment updated.' : 'The assignment is already current.');
    }
}
