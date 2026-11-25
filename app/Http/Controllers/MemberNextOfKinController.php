<?php

namespace App\Http\Controllers;

use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MemberNextOfKinController extends Controller
{
    public function index(int $member): Response
    {
        $profile = DB::table('members')->find($member, ['id', 'registration_no', 'first_name', 'last_name']);
        abort_if($profile === null, 404);
        $contacts = DB::table('member_next_of_kin')->where('member_id', $member)->orderByRaw('lower(full_name)')->orderBy('id')
            ->tap(TablePage::search(['full_name', 'relationship', 'phone', 'email']))
            ->paginate(TablePage::size());

        return response()->view('staff.member-next-of-kin', compact('profile', 'contacts'), headers: ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, int $member): RedirectResponse
    {
        return $this->save($request, $member);
    }

    public function update(Request $request, int $member, int $contact): RedirectResponse
    {
        return $this->save($request, $member, $contact);
    }

    private function save(Request $request, int $member, ?int $contact = null): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:200'],
            'relationship' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'regex:/\A\+?[0-9]{10,15}\z/'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $data += ['email' => null];

        DB::transaction(function () use ($request, $member, $contact, $data): void {
            abort_unless(DB::table('members')->where('id', $member)->exists(), 404);
            $now = now();
            if ($contact === null) {
                $id = DB::table('member_next_of_kin')->insertGetId([
                    ...$data, 'member_id' => $member, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $row = DB::table('member_next_of_kin')->where('member_id', $member)->where('id', $contact);
                $record = (clone $row)->lockForUpdate()->first();
                abort_if($record === null, 404);
                if (array_intersect_key((array) $record, $data) === $data) {
                    return;
                }
                $row->update([...$data, 'updated_at' => $now]);
                $id = $contact;
            }

            DB::table('member_audits')->insert([
                'member_id' => $member, 'actor_id' => $request->user()->id, 'recorded_at' => $now,
                'changes' => json_encode([
                    'next_of_kin' => ['before' => null, 'after' => 'Contact '.$id.($contact === null ? ' added' : ' updated')],
                ], JSON_THROW_ON_ERROR),
            ]);
        }, 3);

        return redirect()->route('staff.members.next-of-kin.index', $member)
            ->with('status', $contact === null ? 'Next of kin added.' : 'Next of kin updated.');
    }
}
