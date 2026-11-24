<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function isBoardOfficer(): bool
    {
        return $this->is_staff_admin !== true && in_array($this->board_role, ['chairperson', 'secretary'], true);
    }

    public function hasLoanRole(string $role): bool
    {
        return $this->is_staff_admin !== true && $this->loan_workflow_role === $role && $this->member_id !== null
            && DB::table('members')->where('id', $this->member_id)->where('status', 'active')->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_staff_admin' => 'boolean',
            'local_setup_pending' => 'boolean',
        ];
    }
}
