<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SetDemoMemberPasswords extends Command
{
    protected $signature = 'members:set-demo-passwords {--password-stdin : Read the password from standard input}';

    protected $description = 'Create missing member logins and reset member passwords in the demo environment';

    public function handle(): int
    {
        if (! config('app.demo_mode')) {
            $this->error('This command requires demo mode.');

            return self::FAILURE;
        }

        if (! $this->option('password-stdin') && ! $this->input->isInteractive()) {
            $this->error('Use an interactive terminal or pass the password through standard input.');

            return self::FAILURE;
        }

        $password = $this->option('password-stdin') ? rtrim((string) fgets(STDIN), "\r\n") : $this->secret('Demo member password', false);
        if (! is_string($password) || strlen($password) > 72 || mb_strlen($password) < 12) {
            $this->error('The password must have at least 12 characters and fit in 72 bytes.');

            return self::FAILURE;
        }

        [$created, $updated, $skipped] = DB::transaction(function () use ($password): array {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['demo-member-passwords']);
            $created = $updated = $skipped = 0;
            $emails = [];
            $userIds = [];
            $members = DB::table('members')->whereNotIn('id', DB::table('members')
                ->whereNotNull('previous_membership_id')->select('previous_membership_id'))
                ->where('email', 'not like', '%@pending.invalid')->orderBy('id')->get(['id', 'email', 'first_name', 'last_name']);
            foreach ($members as $member) {
                $user = User::query()->where('member_id', $member->id)->lockForUpdate()->first();
                $email = Str::lower(trim($user?->email ?? $member->email));
                if ($user?->is_staff_admin
                    || Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()
                    || User::query()->whereRaw('lower(email) = ?', [$email])->when($user, fn ($query) => $query->whereKeyNot($user->id))->exists()) {
                    $skipped++;

                    continue;
                }

                if ($user === null) {
                    $user = new User(['name' => $member->first_name.' '.$member->last_name, 'email' => $email]);
                    $user->member_id = $member->id;
                    $created++;
                } else {
                    $updated++;
                }

                $user->email = $email;
                $user->password = $password;
                $user->local_setup_pending = false;
                $user->setRememberToken(null);
                $user->save();
                $emails[] = $email;
                $userIds[] = $user->id;
            }

            DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))->whereIn('user_id', $userIds)->delete();
            }

            return [$created, $updated, $skipped];
        }, 3);

        $this->info("Member logins created: $created. Passwords reset: $updated. Accounts skipped: $skipped.");

        return $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }
}
