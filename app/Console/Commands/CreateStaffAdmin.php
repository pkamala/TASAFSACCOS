<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateStaffAdmin extends Command
{
    protected $signature = 'staff:create-admin {name} {email} {--role=admin : admin, chairperson, or secretary} {--setup-link : Generate a one-time password setup link}';

    protected $description = 'Create a staff administrator with a password entered privately';

    public function handle(): int
    {
        $useSetupLink = $this->option('setup-link');
        if (! $useSetupLink && ! $this->input->isInteractive()) {
            $this->error('Run this command in an interactive terminal.');

            return self::FAILURE;
        }

        $role = $this->option('role');
        if (! in_array($role, ['admin', 'chairperson', 'secretary'], true)) {
            $this->error('Choose admin, chairperson, or secretary.');

            return self::FAILURE;
        }

        $password = $useSetupLink ? Str::random(64) : $this->secret('Password', false);

        $validator = Validator::make([
            'name' => trim($this->argument('name')),
            'email' => Str::lower(trim($this->argument('email'))),
            'password' => $password,
            'password_confirmation' => $useSetupLink ? $password : $this->secret('Confirm password', false),
        ], [
            'name' => ['required', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12), 'max:72'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $attributes = $validator->validated();
        if (strlen($attributes['password']) > 72) {
            $this->error('The password must fit within 72 bytes.');

            return self::FAILURE;
        }

        $user = new User($attributes);
        $user->is_staff_admin = $role === 'admin';
        $user->board_role = $role === 'admin' ? null : $role;

        if ($role !== 'admin') {
            $user->member_id = DB::table('members')->whereRaw('lower(email) = ?', [$user->email])
                ->where('status', 'active')->value('id');
            if ($user->member_id === null) {
                $this->error('The board officer must match an active SACCOS member by email.');

                return self::FAILURE;
            }
        }

        try {
            $token = DB::transaction(function () use ($user, $useSetupLink): ?string {
                if ($user->board_role !== null) {
                    DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['board-roles']);
                }
                $user->save();

                return $useSetupLink ? PasswordBroker::broker()->createToken($user) : null;
            });
        } catch (UniqueConstraintViolationException) {
            $this->error('This email, member account, or board role is already in use.');

            return self::FAILURE;
        }

        if ($token !== null) {
            $this->line(route('staff.password-setup', ['token' => $token, 'email' => $user->email]));
        } else {
            $this->info("Staff account created with role $role.");
        }

        return self::SUCCESS;
    }
}
