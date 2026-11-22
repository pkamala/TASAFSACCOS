<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RegisterMember extends Command
{
    protected $signature = 'members:register
        {registration_no : Assigned membership number}
        {first_name : First name}
        {last_name : Last name}
        {email : Contact email}
        {phone : Contact phone number}';

    protected $description = 'Register an active SACCO member';

    public function handle(): int
    {
        $validator = Validator::make([
            'registration_no' => Str::upper(trim($this->argument('registration_no'))),
            'first_name' => trim($this->argument('first_name')),
            'last_name' => trim($this->argument('last_name')),
            'email' => Str::lower(trim($this->argument('email'))),
            'phone' => trim($this->argument('phone')),
        ], [
            'registration_no' => ['required', 'max:30', 'regex:/\A[A-Z0-9-]+\z/', 'unique:members'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:members'],
            'phone' => ['required', 'regex:/\A\+?[0-9]{10,15}\z/'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $registeredAt = now();

        try {
            DB::table('members')->insert([
                ...$validator->validated(),
                'joining_date' => $registeredAt->copy()->setTimezone('Africa/Dar_es_Salaam')->toDateString(),
                'status' => 'active',
                'created_at' => $registeredAt,
                'updated_at' => $registeredAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->error('The registration number or email has already been taken.');

            return self::FAILURE;
        }

        $this->info('Member registered.');

        return self::SUCCESS;
    }
}
