<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'key',
        'value',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Get Setting
    |--------------------------------------------------------------------------
    */

    public static function get(
        int $tenantId,
        string $key,
        mixed $default = null
    ): mixed {
        $setting = static::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->first();

        if (!$setting) {
            return $default;
        }

        return $setting->value;
    }

    /*
    |--------------------------------------------------------------------------
    | Set Setting
    |--------------------------------------------------------------------------
    */

    public static function set(
        int $tenantId,
        string $key,
        mixed $value
    ): static {
        return static::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'key' => $key,
            ],
            [
                'value' => static::normalizeValue($value),
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Boolean
    |--------------------------------------------------------------------------
    */

    public static function getBool(
        int $tenantId,
        string $key,
        bool $default = false
    ): bool {
        $value = static::get(
            $tenantId,
            $key,
            null
        );

        if ($value === null) {
            return $default;
        }

        return filter_var(
            $value,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? $default;
    }

    public static function setBool(
        int $tenantId,
        string $key,
        bool $value
    ): static {
        return static::set(
            $tenantId,
            $key,
            $value ? '1' : '0'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize
    |--------------------------------------------------------------------------
    */

    private static function normalizeValue(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );
        }

        return (string) $value;
    }
}
