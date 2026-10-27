<?php

namespace App\Services;

use App\Models\BranchSyncOperation;
use App\Models\TenantSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class DesktopPosConnection
{
    public string $state = 'offline';

    public function enabled(): bool
    {
        return config('app.desktop') && config('branch-sync.mode') === 'edge';
    }

    public function retry(int $tenantId, int $branchId): void
    {
        Cache::forget('desktop-pos-retry:'.$tenantId.':'.$branchId);
    }

    /** @return array{tenant_id: int, branch_id: int, token: string}|null */
    private function connection(int $tenantId, int $branchId): ?array
    {
        if (! $this->enabled() || parse_url((string) config('branch-sync.server_url'), PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        return collect(app(BranchSyncCredentials::class)->all())
            ->first(fn (array $connection): bool => $connection['tenant_id'] === $tenantId && $connection['branch_id'] === $branchId);
    }

    public function hasUnsettledOperations(int $tenantId, int $branchId): bool
    {
        return BranchSyncOperation::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
            ->where('status', '!=', 'synced')->exists();
    }

    public function flush(int $tenantId, int $branchId): void
    {
        $connection = $this->connection($tenantId, $branchId);
        if (! $connection || ! BranchSyncOperation::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
            ->where('status', 'pending')->exists()) {
            return;
        }
        try {
            Artisan::call('branch-sync:push', ['--tenant' => $tenantId, '--branch' => $branchId, '--no-interaction' => true]);
        } catch (\Throwable $exception) {
            report($exception);
        }
        if ($this->hasUnsettledOperations($tenantId, $branchId)) {
            $this->state = 'pending';
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>|null
     */
    public function read(int $tenantId, int $branchId, string $entity = 'products', array $filters = []): ?array
    {
        $connection = $this->connection($tenantId, $branchId);
        if (! $connection) {
            return null;
        }
        $retryKey = 'desktop-pos-retry:'.$tenantId.':'.$branchId;
        $previousFailure = Cache::get($retryKey);
        if (is_string($previousFailure)) {
            $this->state = $previousFailure;

            return null;
        }
        $this->flush($tenantId, $branchId);
        $hadUnsettled = $this->hasUnsettledOperations($tenantId, $branchId);
        $lastOperationId = BranchSyncOperation::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->max('id');
        try {
            $response = Http::acceptJson()->withToken($connection['token'])->connectTimeout(1)->timeout(4)
                ->get(rtrim((string) config('branch-sync.server_url'), '/').'/api/branch-sync/pos-catalog', ['entity' => $entity, ...$filters]);
            if (! $response->successful()) {
                $this->state = match ($response->status()) {
                    404 => 'update_required',
                    401, 403 => 'unauthorized',
                    default => 'unavailable',
                };
                Cache::put($retryKey, $this->state, now()->addSeconds(3));

                return null;
            }
            $page = $response->json();
            Validator::make($page, [
                'version' => ['required', 'integer', 'in:1'],
                'entity' => ['required', 'in:'.$entity],
                'tenant_id' => ['required', 'integer', 'in:'.$tenantId],
                'branch_id' => ['required', 'integer', 'in:'.$branchId],
                'rows' => ['present', 'array', 'max:100'],
                'rows.*.id' => ['required', 'integer', 'min:1'],
                'rows.*.tenant_id' => ['required', 'integer', 'in:'.$tenantId],
                'rows.*.name' => ['required', 'string'],
                'categories' => ['present', 'array'], 'barcodes' => ['present', 'array'],
                'prices' => ['present', 'array'], 'offers' => ['present', 'array'],
                'settings' => ['required', 'array:allow_negative_stock,unified_stock,merge_similar_products'],
                'settings.*' => ['boolean'], 'available_stock' => ['present', 'array'],
                'available_stock.*' => ['numeric'],
            ])->validate();
            $ids = array_map('intval', array_column($page['rows'], 'id'));
            if ($entity === 'products' && (count($page['prices']) !== count($ids)
                || array_diff($ids, array_column($page['prices'], 'product_id')) !== [])) {
                throw new RuntimeException('Incomplete branch prices in live catalog.');
            }
            DB::transaction(function () use ($page, $tenantId, $branchId, $entity, $ids, $hadUnsettled, $lastOperationId): void {
                $unsettled = $hadUnsettled || $this->hasUnsettledOperations($tenantId, $branchId)
                    || $lastOperationId !== BranchSyncOperation::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->max('id');
                foreach ($page['settings'] as $key => $value) {
                    TenantSetting::setBool($tenantId, $key, (bool) $value);
                }
                foreach ($page['categories'] as $row) {
                    $this->store('categories', $row, $tenantId);
                }
                foreach ($page['rows'] as $row) {
                    if ($entity === 'customers' && ($row['branch_id'] ?? null) !== null && (int) $row['branch_id'] !== $branchId) {
                        throw new RuntimeException('Unexpected customer branch.');
                    }
                    $this->store($entity === 'products' ? 'products' : 'parties', $row, $tenantId,
                        $unsettled && $entity === 'customers' ? ['opening_balance', 'current_balance'] : []);
                }
                if ($entity !== 'products') {
                    return;
                }
                foreach (['product_barcodes' => 'barcodes', 'branch_products' => 'prices', 'product_offers' => 'offers'] as $table => $key) {
                    if ($key !== 'prices') {
                        DB::table($table)->where('tenant_id', $tenantId)->whereIn('product_id', $ids)
                            ->when($key === 'offers', fn ($query) => $query->where('branch_id', $branchId))->delete();
                    }
                    foreach ($page[$key] as $row) {
                        if (! in_array((int) ($row['product_id'] ?? 0), $ids, true)
                            || ($key !== 'barcodes' && (int) ($row['branch_id'] ?? 0) !== $branchId)) {
                            throw new RuntimeException('Unexpected product or branch in live catalog.');
                        }
                        $this->store($table, $row, $tenantId, $unsettled && $key === 'prices' ? ['stock_quantity'] : []);
                    }
                }
                if (! $unsettled) {
                    $stock = json_decode((string) TenantSetting::get($tenantId, 'desktop_shared_stock_'.$branchId, '{}'), true) ?: [];
                    foreach ($page['available_stock'] as $id => $quantity) {
                        if (! in_array((int) $id, $ids, true)) {
                            throw new RuntimeException('Unexpected shared stock product.');
                        }
                        $stock[$id] = (float) $quantity;
                    }
                    TenantSetting::set($tenantId, 'desktop_shared_stock_'.$branchId, json_encode($stock, JSON_THROW_ON_ERROR));
                }
            });
            $this->state = $this->hasUnsettledOperations($tenantId, $branchId) ? 'pending' : 'online';

            return $ids;
        } catch (ConnectionException) {
            $this->state = 'offline';
        } catch (\Throwable $exception) {
            report($exception);
            $this->state = 'unavailable';
        }

        Cache::put($retryKey, $this->state, now()->addSeconds(3));

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $preserved
     */
    private function store(string $table, array $row, int $tenantId, array $preserved = []): void
    {
        if ((int) ($row['tenant_id'] ?? 0) !== $tenantId || ! isset($row['id'])) {
            throw new RuntimeException('Unexpected live catalog tenant.');
        }
        $existing = DB::table($table)->where('id', $row['id'])->lockForUpdate()->first();
        if ($existing && ((int) $existing->tenant_id !== $tenantId
            || (isset($row['branch_id']) && isset($existing->branch_id) && (int) $row['branch_id'] !== (int) $existing->branch_id)
            || (isset($row['product_id']) && (int) $row['product_id'] !== (int) $existing->product_id))) {
            throw new RuntimeException('Live catalog identifier conflict.');
        }
        $row = Arr::only(Arr::except($row, ['created_by', 'updated_by']), Schema::getColumnListing($table));
        if ($existing) {
            DB::table($table)->where('id', $row['id'])->update(Arr::except($row, $preserved));
        } else {
            DB::table($table)->insert($row);
        }
    }
}
