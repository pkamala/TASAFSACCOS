<?php

namespace App\Http\Controllers;

use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MemberAgmController extends Controller
{
    public function index(Request $request): View
    {
        $member = DB::table('members')->find($request->user()->member_id);
        abort_if($member === null, 403);

        $meetings = DB::table('agm_meetings as meeting')
            ->leftJoin('agm_registrations as registration', fn ($join) => $join
                ->on('registration.agm_meeting_id', '=', 'meeting.id')->where('registration.member_id', $member->id))
            ->where(fn ($query) => $query->whereNull('meeting.registration_closed_at')->orWhereNotNull('registration.id'))
            ->orderBy('meeting.starts_at')->orderBy('meeting.id')
            ->tap(TablePage::search(['meeting.title', 'meeting.venue', 'meeting.starts_at']))
            ->paginate(TablePage::size(), ['meeting.*', 'registration.registered_at']);

        return view('member.agm', ['meetings' => $meetings, 'active' => $member->status === 'active']);
    }

    public function register(Request $request, int $meeting): RedirectResponse
    {
        $memberId = $request->user()->member_id;
        abort_if($memberId === null, 403);

        $registered = DB::transaction(function () use ($meeting, $memberId): bool {
            $event = DB::table('agm_meetings')->lockForUpdate()->find($meeting);
            abort_if($event === null, 404);
            $member = DB::table('members')->lockForUpdate()->find($memberId);
            abort_if($member === null || $member->status !== 'active', 403);

            if (DB::table('agm_registrations')->where('agm_meeting_id', $meeting)->where('member_id', $memberId)->exists()) {
                return false;
            }
            if ($event->registration_closed_at !== null) {
                throw ValidationException::withMessages(['registration' => 'Registration has closed for this AGM.']);
            }

            DB::table('agm_registrations')->insert([
                'agm_meeting_id' => $meeting, 'member_id' => $memberId,
                'registration_no' => $member->registration_no,
                'member_name' => implode(' ', array_filter([$member->first_name, $member->middle_name, $member->last_name])),
                'email' => $member->email, 'phone' => $member->phone, 'registered_at' => now(),
            ]);

            return true;
        }, 3);

        return redirect()->route('member.agm.index')->with('status', $registered ? 'You’re registered for the AGM.' : 'You’re already registered for this AGM.');
    }
}
