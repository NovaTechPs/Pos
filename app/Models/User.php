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
#[Fillable(['name', 'email', 'password'])]
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

    // ... باقي إعدادات النموذج

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function isBranchManager(): bool
    {
        return ! $this->is_owner && $this->role?->name === 'Branch Manager';
    }

    /**
     * فحص هل المستخدم كاشير
     */
    public function isCashier(): bool
    {
        return ! $this->is_owner && $this->role?->name === 'Cashier';
    }

    /**
     * التحقق مما إذا كان المستخدم يمتلك صلاحية معينة
     */
    public function hasPermission(string $permissionName): bool
    {
        // صاحب المتجر (Owner) يملك جميع الصلاحيات
        if ($this->is_owner) {
            return true;
        }

        // التحقق من وجود علاقة Role (عبر استدعاء الدالة للتأكد من أنها Eloquent Relation)
        if (! $this->role()->exists()) {
            return false;
        }

        // فحص وجود الصلاحية من خلال كائن الـ Role
        return $this->role->permissions->contains('name', $permissionName);
    }
}
