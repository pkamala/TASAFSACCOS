<?php

namespace App\Http\Controllers;

use App\Mail\MemberLoginLinkMail;
use App\Models\User;
use App\StatementMailer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MemberLoginController extends Controller
{
    public function __invoke(Request $request, int $member, StatementMailer $mailer): RedirectResponse
    {
        $account = $mailer->selectedAccountId();
        if (! $mailer->configured($account)) {
            throw ValidationException::withMessages(['login' => 'Configure an outgoing mailing account before sending member login emails.']);
        }

        $actor = $request->user()->id;
        try {
            [$email, $url, $setup] = DB::transaction(function () use ($actor, $member): array {
                $profile = DB::table('members')->lockForUpdate()->find($member);
                abort_if($profile === null, 404);
                $user = User::query()->where('member_id', $member)->lockForUpdate()->first();
                abort_if($user?->is_staff_admin, 403);
                $created = $user === null;
                if ($created) {
                    $email = Str::lower(trim($profile->email));
                    if ($profile->status !== 'active' || Str::endsWith($email, '@pending.invalid') || Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
                        throw ValidationException::withMessages(['login' => 'A new login requires an active member with a valid email address.']);
                    }
                    if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                        throw ValidationException::withMessages(['login' => 'That email is already assigned to another login.']);
                    }

                    $user = new User(['name' => $profile->first_name.' '.$profile->last_name, 'email' => $email, 'password' => Str::random(64)]);
                    $user->member_id = $member;
                    $user->local_setup_pending = true;
                    $user->save();
                }

                $token = Password::broker()->createToken($user);
                $setup = $user->local_setup_pending === true;
                $this->audit($member, $actor, 'login', $created ? 'No account' : 'Existing account', $setup ? 'Setup link issued' : 'Reset link issued');
                $url = rtrim(config('app.url'), '/').route('staff.password-setup', ['email' => $user->email, 'token' => $token], absolute: false);

                return [$user->email, $url, $setup];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['login' => 'That email or member is already assigned to another login.']);
        }

        $redirect = redirect()->route('staff.members.show', $member)->withFragment('member-login');
        $expiry = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        try {
            $mailer->forDelivery($account)->to($email)->send(new MemberLoginLinkMail($url, $setup, $expiry));
        } catch (Throwable) {
            $this->audit($member, $actor, 'login_email', 'Delivery attempted', 'Delivery not confirmed');

            return $redirect->withErrors(['login' => 'The mail provider did not confirm delivery. Check the outgoing mailing account and send a new link.']);
        }
        $this->audit($member, $actor, 'login_email', 'Delivery attempted', 'Accepted by mail transport');

        return $redirect->with('status', 'Login email sent to '.$email.'.');
    }

    private function audit(int $member, int $actor, string $field, string $before, string $after): void
    {
        DB::table('member_audits')->insert([
            'member_id' => $member, 'actor_id' => $actor,
            'changes' => json_encode([$field => compact('before', 'after')], JSON_THROW_ON_ERROR),
            'recorded_at' => now(),
        ]);
    }
}
