<?php

namespace App\Http\Controllers;

use App\TablePage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgmMeetingController extends Controller
{
    public function index(): View
    {
        $meetings = DB::table('agm_meetings as meeting')->select('meeting.*')
            ->selectSub(DB::table('agm_registrations as registration')->selectRaw('count(*)')
                ->whereColumn('registration.agm_meeting_id', 'meeting.id'), 'registrations')
            ->orderByDesc('meeting.starts_at')->orderByDesc('meeting.id')
            ->tap(TablePage::search(['meeting.title', 'meeting.venue', 'meeting.starts_at']))
            ->paginate(TablePage::size());

        return view('staff.agm.index', compact('meetings'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'venue' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);
        $meeting = DB::table('agm_meetings')->insertGetId([
            ...$data,
            'starts_at' => CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['starts_at'], 'Africa/Dar_es_Salaam')->toIso8601String(),
            'created_by' => $request->user()->id, 'created_at' => now(),
        ]);

        return redirect()->route('staff.agm.show', $meeting)->with('status', 'AGM created. Members can now register.');
    }

    public function show(int $meeting): View
    {
        $event = DB::table('agm_meetings')->find($meeting);
        abort_if($event === null, 404);
        $counts = $this->registerQuery($meeting)->reorder()->selectRaw('count(*) as registered, count(checked_in_at) as checked_in')->first();
        $registrations = $this->registerQuery($meeting)
            ->tap(TablePage::search(['registration_no', 'member_name', 'email', 'phone']))
            ->paginate(TablePage::size());

        return view('staff.agm.show', compact('event', 'counts', 'registrations'));
    }

    public function updateRegistration(Request $request, int $meeting): RedirectResponse
    {
        $data = $request->validate(['open' => ['required', 'boolean']]);
        $updated = DB::table('agm_meetings')->where('id', $meeting)
            ->update(['registration_closed_at' => $data['open'] ? null : now()]);
        abort_if($updated === 0, 404);

        return redirect()->route('staff.agm.show', $meeting)->with('status', $data['open'] ? 'Registration opened.' : 'Registration closed.');
    }

    public function checkIn(Request $request, int $meeting, int $registration): RedirectResponse
    {
        $row = $this->registerQuery($meeting)->where('id', $registration);
        abort_unless($row->exists(), 404);
        $row->whereNull('checked_in_at')->update([
            'checked_in_at' => now(), 'checked_in_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Attendance recorded.');
    }

    public function export(int $meeting): StreamedResponse
    {
        abort_unless(DB::table('agm_meetings')->where('id', $meeting)->exists(), 404);

        return response()->streamDownload(function () use ($meeting): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, ['Membership number', 'Full name', 'Email', 'Phone', 'Registered at (EAT)', 'Checked in at (EAT)'], escape: '');
            foreach ($this->registerQuery($meeting)->cursor() as $row) {
                fputcsv($stream, [
                    ...array_map($this->csvText(...), [$row->registration_no, $row->member_name, $row->email, $row->phone]),
                    $this->localTime($row->registered_at), $this->localTime($row->checked_in_at),
                ], escape: '');
            }
            fclose($stream);
        }, "agm-$meeting-register.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function registerQuery(int $meeting): Builder
    {
        return DB::table('agm_registrations')->where('agm_meeting_id', $meeting)->orderByRaw('lower(member_name)')->orderBy('id');
    }

    private function csvText(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\x00-\x20]*[=+@-]|^[\t\r\n]/', $value) ? "'".$value : $value;
    }

    private function localTime(?string $value): string
    {
        return $value === null ? '' : CarbonImmutable::parse($value)->setTimezone('Africa/Dar_es_Salaam')->format('Y-m-d H:i:s');
    }
}
