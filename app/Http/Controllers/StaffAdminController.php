<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\TablePage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StaffAdminController extends Controller
{
    public function index(): View
    {
        $admins = User::query()->where('is_staff_admin', true)->orderBy('name')->orderBy('id')
            ->tap(TablePage::search(['id', 'name', 'email']))
            ->paginate(TablePage::size(), ['id', 'name', 'email']);

        return view('staff.admin-accounts', compact('admins'));
    }

    public function store(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::min(12), 'max:72'],
        ]);

        if (strlen($data['password']) > 72) {
            throw ValidationException::withMessages(['password' => 'The password must fit within 72 bytes.']);
        }
        if (User::query()->whereRaw('lower(email) = ?', [$data['email']])->exists()
            || DB::table('members')->whereRaw('lower(email) = ?', [$data['email']])->exists()) {
            throw ValidationException::withMessages(['email' => 'Use an email address that is not assigned to another account or member.']);
        }

        try {
            DB::transaction(function () use ($request, $data): void {
                $admin = new User($data);
                $admin->is_staff_admin = true;
                $admin->save();
                DB::table('admin_profile_audits')->insert([
                    'user_id' => $admin->id,
                    'changes' => json_encode(['created_by' => $request->user()->id], JSON_THROW_ON_ERROR),
                    'recorded_at' => now(),
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email address is already in use.']);
        }

        return redirect()->route('staff.admin-accounts.index')->with('status', 'Administrator account created.');
    }
}
