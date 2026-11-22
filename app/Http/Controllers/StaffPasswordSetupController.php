<?php

namespace App\Http\Controllers;

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

        return response()->view('staff.password-setup', $data, headers: [
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', PasswordRule::min(12), 'max:72'],
        ]);
        if (strlen($data['password']) > 72) {
            throw ValidationException::withMessages(['password' => 'The password must fit within 72 bytes.']);
        }

        $success = DB::transaction(function () use ($data): bool {
            $broker = Password::broker();
            $user = User::query()->where('email', Str::lower($data['email']))->lockForUpdate()->first();
            if (! $user || ($user->member_id === null && ! $user->can('access-loan-workflow'))
                || ! $broker->tokenExists($user, $data['token'])) {
                return false;
            }

            $user->password = $data['password'];
            $user->local_setup_pending = false;
            $user->setRememberToken(Str::random(60));
            $user->save();
            $broker->deleteToken($user);

            return true;
        }, 3);

        if (! $success) {
            throw ValidationException::withMessages(['email' => 'This setup link has expired, was already used, or is invalid.']);
        }

        return redirect()->route('login')->with('status', 'Password set. You can sign in now.');
    }
}
