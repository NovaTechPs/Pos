<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements PasskeyUser
{
    protected $attributes = ['is_active' => true, 'is_owner' => false, 'type' => 'tenant_user'];

    use HasApiTokens, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'password',
        'type',
        'is_active',
        'tenant_id',
        'branch_id',
        'is_owner',
        'role_id',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
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
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /** @return HasMany<Tenant, $this> */
    public function ownedTenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'owner_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function canAccessApplication(): bool
    {
        return $this->is_active && (! $this->tenant_id || $this->tenant?->is_active === true);
    }

    /** @return HasMany<Tenant, $this> */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'owner_id');
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasOne<Employee, $this> */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
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

        $role = $this->role;

        if (! $role || (int) $role->tenant_id !== (int) $this->tenant_id) {
            return false;
        }

        return $role->permissions()
            ->where('name', $permissionName)
            ->exists();
    }
}
