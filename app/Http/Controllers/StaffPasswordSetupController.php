<?php

namespace App\Http\Controllers;

use App\LocalMemberSetup;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class StaffPasswordSetupController extends Controller
{
    public function show(Request $request): Response
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'size:64'],
        ]);

        return response()->view('staff.password-setup', [...$data, 'local' => false], headers: [
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function showLocal(Request $request, LocalMemberSetup $setup): Response
    {
        abort_unless($setup->available($request), 404);

        return response()->view('staff.password-setup', ['local' => true], headers: [
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function storeLocal(Request $request, LocalMemberSetup $setup): RedirectResponse
    {
        abort_unless($setup->available($request), 404);

        return $this->setPassword($request, true);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->setPassword($request, false);
    }

    private function setPassword(Request $request, bool $local): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => $local ? ['prohibited'] : ['required', 'string', 'size:64'],
            'registration_no' => $local ? ['required', 'string', 'max:30'] : ['prohibited'],
            'password' => ['required', 'confirmed', PasswordRule::min(12), 'max:72'],
        ]);
        if (strlen($data['password']) > 72) {
            throw ValidationException::withMessages(['password' => 'The password must fit within 72 bytes.']);
        }

        $email = Str::lower($data['email']);
        $success = DB::transaction(function () use ($email, $data, $local): bool {
            $broker = Password::broker();
            if ($local) {
                $member = DB::table('members')->where('registration_no', Str::upper($data['registration_no']))
                    ->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();
                if (! $member) {
                    return false;
                }

                $user = User::query()->where('email', $email)->lockForUpdate()->first();
                if ($user !== null) {
                    if ($user->member_id !== $member->id || $user->local_setup_pending !== true) {
                        return false;
                    }
                } else {
                    if ($member->status !== 'active' || User::query()->where('member_id', $member->id)->exists()) {
                        return false;
                    }

                    $user = new User([
                        'name' => $member->first_name.' '.$member->last_name,
                        'email' => $email,
                    ]);
                    $user->member_id = $member->id;
                }
            } else {
                $user = User::query()->where('email', $email)->lockForUpdate()->first();
                if (! $user || ! $user->can('access-loan-workflow')
                    || $user->local_setup_pending === true || ! $broker->tokenExists($user, $data['token'])) {
                    return false;
                }
            }

            $user->password = $data['password'];
            $user->local_setup_pending = false;
            $user->setRememberToken(Str::random(60));
            $user->save();
            $broker->deleteToken($user);

            return true;
        }, 3);

        if (! $success) {
            throw ValidationException::withMessages(['email' => $local
                ? 'Check your registered email and membership number. If you already created an account, sign in.'
                : 'This setup link has expired, was already used, or is invalid.']);
        }

        return redirect()->route('login')->with('status', 'Password set. You can sign in now.');
    }
}
