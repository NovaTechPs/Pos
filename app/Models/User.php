<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'type', 'is_active',
    'tenant_id', 'branch_id', 'is_owner', 'role_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes , TwoFactorAuthenticatable;

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
            'is_active' => 'boolean',
            'is_owner' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    public function ownedTenants()
    {
        return $this->hasMany(Tenant::class, 'user_id');
    }

    // المتجر الذي يعمل به حالياً (سواء كان موظف أو المالك في الجلسة الحالية)
  public function tenants()
{
    return $this->hasMany(Tenant::class,'owner_id');
}

    /**
     * 1. هل المستخدم مدير المنصة بالكامل (SaaS Admin)؟
     */
    public function isSaaSAdmin(): bool
    {
        return $this->type === 'saas_admin';
    }

    public function isTenantOwner(): bool
    {
        return $this->type === 'tenant_user' && $this->is_owner;
    }
    // ... باقي إعدادات النموذج

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function isBranchManager(): bool
    {
        return ! $this->is_owner && $this->role?->name === 'Branch Manager';
    }

    public function isTenantEmployee(): bool
    {
        return ! is_null($this->tenant_id) && $this->is_owner === false;
    }

    /**
     * فحص هل المستخدم كاشير
     */
    public function isCashier(): bool
    {
        return ! $this->is_owner && $this->role?->name === 'Cashier';
    }

    public function branch()
    {

        return $this->belongsTo(Branch::class);
    }

    /**
     * التحقق مما إذا كان المستخدم يمتلك صلاحية معينة
     */
    public function hasPermission(string $permissionName): bool
    {
        // 1. SaaS Admin لديه كل الصلاحيات
        if ($this->isSaaSAdmin()) {
            return true;
        }

        // 2. مالك المتجر لديه كل صلاحيات متجره
        if ($this->isTenantOwner()) {
            return true;
        }

        // 3. الموظف العادي: يتم الفحص عبر role_id و جدول الصلاحيات المربوط به
        if (! $this->relationLoaded('role')) {
            $this->load('role.permissions');
        }

        return $this->role
            ? $this->role->permissions->contains('name', $permissionName)
            : false;
    }
}
