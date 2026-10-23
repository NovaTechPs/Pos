<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
        ]);

        $tenantId = $request->user()->tenant_id;
        abort_if(isset($validated['tenant_id']) && (int) $validated['tenant_id'] !== (int) $tenantId, 404);
        $theme = TenantSetting::get((int) $tenantId, 'ui_theme', 'blue');
        $theme = in_array($theme, ['blue', 'orange', 'green'], true) ? $theme : 'blue';

        return response()->json([
            'theme' => [
                'name' => $theme,
                'primary_color' => match ($theme) {
                    'orange' => '#EA580C',
                    'green' => '#059669',
                    default => '#2563EB',
                },
            ],
        ]);
    }
}
