<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable implements PasskeyUser
{
    use HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'type',
        'is_active',
        'tenant_id',
        'branch_id',
        'is_owner',
        'role_id'
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token'
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_owner' => 'boolean',
        ];
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1) . Str::substr($initials, -1)
            : $initials;
    }

    public function ownedTenants()
    {
        return $this->hasMany(Tenant::class, 'owner_id');
    }

    public function tenants()
    {
        return $this->hasMany(Tenant::class, 'owner_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function isSaaSAdmin(): bool
    {
        return $this->type === 'saas_admin';
    }

    public function isTenantOwner(): bool
    {
        return $this->type === 'tenant_user' && $this->is_owner;
    }

    public function isBranchManager(): bool
    {
        return ! $this->is_owner && $this->role?->name === 'Branch Manager';
    }

    public function isTenantEmployee(): bool
    {
        return ! is_null($this->tenant_id) && $this->is_owner === false;
    }

    public function isCashier(): bool
    {
        return ! $this->is_owner && $this->role?->name === 'Cashier';
    }


public function hasPermission(string $permissionName): bool
{
    if ($this->isSaaSAdmin() || $this->isTenantOwner()) {
        return true;
    }

    if (!$this->role_id) {
        return false;
    }

    return $this->role
        ->permissions()
        ->where('name', $permissionName)
        ->exists();
}


}
