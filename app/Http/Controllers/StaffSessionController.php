<?php

namespace App\Http\Controllers;

use App\LocalMemberSetup;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StaffSessionController extends Controller
{
    public function show(Request $request, LocalMemberSetup $setup): View
    {
        return view('staff.login', ['localSetupAvailable' => $setup->available($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $credentials['email'] = Str::lower($credentials['email']);

        if (! Auth::attemptWhen($credentials, fn (User $user): bool => $user->local_setup_pending !== true && ($user->member_id !== null || $user->can('access-loan-workflow')))) {
            return back()->withErrors(['email' => 'The sign-in details could not be verified.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        if ($request->user()->member_id !== null && ! $request->user()->is_staff_admin) {
            return redirect()->route('member.account');
        }

        if ($request->user()->isBoardOfficer()) {
            return redirect()->route('staff.loan-applications.queue');
        }

        return redirect()->intended(route('staff.members.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
