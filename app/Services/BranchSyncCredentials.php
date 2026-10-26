<?php

namespace App\Services;

use App\Models\TenantSetting;
use Illuminate\Support\Facades\Crypt;

class BranchSyncCredentials
{
    /** @return list<array{tenant_id: int, branch_id: int, token: string}> */
    public function all(): array
    {
        $credentials = [];
        foreach (TenantSetting::query()->where('key', 'like', 'branch_sync_device_%')->get() as $setting) {
            $data = json_decode(Crypt::decryptString($setting->value), true, flags: JSON_THROW_ON_ERROR);
            if ($data['server_url'] === rtrim((string) config('branch-sync.server_url'), '/')) {
                $credentials[] = ['tenant_id' => (int) $setting->tenant_id,
                    'branch_id' => (int) $data['branch_id'], 'token' => $data['token']];
            }
        }

        if ($credentials === [] && config('branch-sync.token')) {
            $credentials[] = ['tenant_id' => (int) config('branch-sync.tenant_id'),
                'branch_id' => (int) config('branch-sync.branch_id'), 'token' => (string) config('branch-sync.token')];
        }

        return $credentials;
    }

    public function remember(int $tenantId, int $branchId, string $token): void
    {
        TenantSetting::query()->updateOrCreate(['tenant_id' => $tenantId, 'key' => 'branch_sync_device_'.$branchId], [
            'value' => Crypt::encryptString(json_encode([
                'branch_id' => $branchId, 'token' => $token,
                'server_url' => rtrim((string) config('branch-sync.server_url'), '/'),
            ], JSON_THROW_ON_ERROR)),
        ]);
    }
}
