<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('staff.admin-profile', ['admin' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'current_password' => ['required_with:password', 'nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'confirmed', Password::min(12), 'max:72'],
        ]);
        $newPassword = $data['password'] ?? null;
        if ($newPassword !== null && strlen($newPassword) > 72) {
            throw ValidationException::withMessages(['password' => 'The password must fit within 72 bytes.']);
        }

        $user = $request->user();
        $updated = DB::transaction(function () use ($data, $newPassword, $user): User {
            $admin = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($admin->can('access-loan-workflow'), 403);
            $changes = [];

            if ($admin->name !== $data['name']) {
                $changes['name'] = ['before' => $admin->name, 'after' => $data['name']];
                $admin->name = $data['name'];
            }

            if ($newPassword !== null) {
                if (! Hash::check($data['current_password'], $admin->getAuthPassword())) {
                    throw ValidationException::withMessages(['current_password' => 'The current password was incorrect.']);
                }

                $admin->password = $newPassword;
                $admin->setRememberToken(Str::random(60));
                $changes['password_changed'] = true;
            }

            if ($changes !== []) {
                $admin->save();
                DB::table('admin_profile_audits')->insert([
                    'user_id' => $admin->id,
                    'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
                    'recorded_at' => now(),
                ]);
            }

            return $admin;
        }, 3);

        $user->setRawAttributes($updated->getAttributes(), true);
        if ($newPassword !== null) {
            $request->session()->regenerate(true);
        }

        return redirect()->route('staff.profile.edit')->with('status', 'Administrator profile saved.');
    }
}
