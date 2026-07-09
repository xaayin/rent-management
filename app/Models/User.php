<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable, TwoFactorAuthenticatable;

    /**
     * Whether the user may start this action at all (directly or via approval).
     */
    public function mayInitiate(Permission $permission): bool
    {
        return $this->hasPermissionTo($permission->value);
    }

    /**
     * Whether the user may perform this action directly, with immediate effect
     * and no supervisor approval.
     */
    public function mayActWithoutApproval(Permission $permission): bool
    {
        $direct = $permission->directVariant();

        return $direct !== null
            ? $this->hasPermissionTo($direct->value)
            : $this->hasPermissionTo($permission->value);
    }

    /**
     * Whether the user may start this action but must have it approved by a
     * supervisor before it takes effect (the matrix `A` cells).
     */
    public function requiresApprovalFor(Permission $permission): bool
    {
        return $this->mayInitiate($permission) && ! $this->mayActWithoutApproval($permission);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('user');
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
        ];
    }
}
