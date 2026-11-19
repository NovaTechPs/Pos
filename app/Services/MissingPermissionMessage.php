<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MissingPermissionMessage
{
    /**
     * An Arabic explanation of a refused API request, naming the permission the route requires
     * (from its «can:» middleware) when there is one.
     */
    public static function for(Request $request): string
    {
        $abilities = collect($request->route()?->gatherMiddleware() ?? [])
            ->filter(fn ($middleware): bool => is_string($middleware) && Str::startsWith($middleware, 'can:'))
            ->map(fn (string $middleware): string => Str::before(Str::after($middleware, 'can:'), ','))
            ->values();

        $user = $request->user();
        $missing = $abilities->first(fn (string $ability): bool => ! $user?->can($ability));

        if ($missing === null) {
            return 'لا تملك صلاحية تنفيذ هذا الإجراء. اطلب من صاحب المتجر إضافة الصلاحية لدورك.';
        }

        $label = DB::table('permissions')->where('name', $missing)->value('display_name') ?: $missing;

        return 'لا تملك صلاحية «'.$label.'». اطلب من صاحب المتجر إضافتها لدورك من «الأدوار والصلاحيات».';
    }
}
