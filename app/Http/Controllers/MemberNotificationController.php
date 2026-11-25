<?php

namespace App\Http\Controllers;

use App\MemberNotifications;
use App\TablePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberNotificationController extends Controller
{
    public function index(Request $request, MemberNotifications $notifications): View
    {
        $member = $request->user()->member_id;
        abort_if($member === null, 403);
        $updates = $notifications->updates($member)->orderByDesc('occurred_at')->orderBy('kind')
            ->orderByDesc('reference')
            ->tap(TablePage::search(['kind', 'reference', 'status', 'occurred_at']))
            ->paginate(TablePage::size());

        return view('member.notifications', compact('updates'));
    }

    public function count(Request $request, MemberNotifications $notifications): JsonResponse
    {
        $member = $request->user()->member_id;
        abort_if($member === null, 403);

        return response()->json(['count' => $notifications->pendingSponsorships($member)->count()])
            ->header('Cache-Control', 'private, no-store');
    }
}
