<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\TenantSetting;
use App\Services\BranchSyncCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class PullBranchSyncCatalog extends Command
{
    protected $signature = 'branch-sync:pull';

    protected $description = 'Download central products, barcodes, prices and customers for offline sales.';

    public function handle(BranchSyncCredentials $credentials): int
    {
        if (config('branch-sync.mode') !== 'edge') {
            $this->error('Catalog synchronization is available only in edge mode.');

            return self::FAILURE;
        }
        $connections = $credentials->all();
        if ($connections === []) {
            $this->error('Sign in online with an employee account to prepare branch synchronization.');

            return self::FAILURE;
        }
        $result = self::SUCCESS;
        foreach ($connections as $connection) {
            if ($this->pullForBranch($connection) !== self::SUCCESS) {
                $result = self::FAILURE;
            }
        }

        return $result;
    }

    /** @param array{tenant_id: int, branch_id: int, token: string} $connection */
    private function pullForBranch(array $connection): int
    {
        $serverUrl = rtrim((string) config('branch-sync.server_url'), '/');
        $token = $connection['token'];
        $tenantId = $connection['tenant_id'];
        $branchId = $connection['branch_id'];

        if (config('branch-sync.mode') !== 'edge' || parse_url($serverUrl, PHP_URL_SCHEME) !== 'https'
            || $token === '' || ! Branch::query()->where('tenant_id', $tenantId)->whereKey($branchId)->exists()) {
            $this->error('Configure edge mode, HTTPS server URL, token and an existing local tenant/branch with matching central IDs.');

            return self::FAILURE;
        }

        try {
            foreach (['products', 'customers'] as $entity) {
                $after = 0;
                do {
                    $response = Http::acceptJson()->withToken($token)->connectTimeout(3)->timeout(20)
                        ->get($serverUrl.'/api/branch-sync/catalog', ['entity' => $entity, 'after' => $after])->throw();
                    $page = $response->json();
                    Validator::make($page, [
                        'tenant_id' => ['required', 'integer', 'in:'.$tenantId],
                        'branch_id' => ['required', 'integer', 'in:'.$branchId],
                        'rows' => ['present', 'array', 'max:100'],
                        'rows.*.id' => ['required', 'integer', 'min:1'],
                        'rows.*.tenant_id' => ['required', 'integer', 'in:'.$tenantId],
                        'rows.*.name' => ['required', 'string', 'max:255'],
                        'next' => ['present', 'nullable', 'integer', 'gt:'.$after],
                        'categories' => ['present', 'array'],
                        'barcodes' => ['present', 'array'],
                        'prices' => ['present', 'array'],
                        'settings' => ['sometimes', 'array:allow_negative_stock,unified_stock,merge_similar_products'],
                        'settings.*' => ['boolean'],
                    ])->validate();
                    DB::transaction(function () use ($page, $entity, $tenantId, $branchId): void {
                        foreach ($page['settings'] ?? [] as $key => $value) {
                            TenantSetting::setBool($tenantId, $key, (bool) $value);
                        }
                        foreach ($page['categories'] as $category) {
                            $this->storeRow('categories', $category, $tenantId);
                        }
                        foreach ($page['rows'] as $row) {
                            if ($entity === 'customers' && ($row['branch_id'] ?? null) !== null && (int) $row['branch_id'] !== $branchId) {
                                throw new RuntimeException('Customer belongs to another branch.');
                            }
                            $this->storeRow($entity === 'products' ? 'products' : 'parties', $row, $tenantId,
                                $entity === 'customers' ? ['current_balance', 'opening_balance'] : []);
                        }
                        if ($entity === 'products') {
                            $productIds = array_column($page['rows'], 'id');
                            DB::table('product_barcodes')->where('tenant_id', $tenantId)->whereIn('product_id', $productIds)->delete();
                            foreach ($page['barcodes'] as $barcode) {
                                if (! in_array($barcode['product_id'], $productIds, true)) {
                                    throw new RuntimeException('Unexpected barcode product.');
                                }
                                $this->storeRow('product_barcodes', $barcode, $tenantId);
                            }
                            foreach ($page['prices'] as $price) {
                                if ((int) $price['branch_id'] !== $branchId || ! in_array($price['product_id'], $productIds, true)) {
                                    throw new RuntimeException('Unexpected branch price.');
                                }
                                $this->storeRow('branch_products', $price, $tenantId, ['stock_quantity']);
                            }
                        }
                    });
                    $after = $page['next'];
                } while ($after !== null);
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->warn('Catalog synchronization failed. Local data is retained; the next scheduled run will retry.');

            return self::FAILURE;
        }

        $this->info('Products and customers are available locally.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $preservedColumns
     */
    private function storeRow(string $table, array $row, int $tenantId, array $preservedColumns = []): void
    {
        if ((int) ($row['tenant_id'] ?? 0) !== $tenantId || ! isset($row['id'])) {
            throw new RuntimeException('Unexpected catalog tenant or missing identifier.');
        }
        $existing = DB::table($table)->where('id', $row['id'])->lockForUpdate()->first();
        if ($existing && (int) $existing->tenant_id !== $tenantId) {
            throw new RuntimeException('Catalog identifier conflicts with another local tenant.');
        }
        if ($existing && $table === 'branch_products'
            && ((int) $existing->branch_id !== (int) $row['branch_id'] || (int) $existing->product_id !== (int) $row['product_id'])) {
            throw new RuntimeException('Catalog price identifier conflicts with another branch or product.');
        }
        $row = Arr::except($row, ['created_by', 'updated_by']);
        if ($existing) {
            DB::table($table)->where('id', $row['id'])->update(Arr::except($row, $preservedColumns));
        } else {
            DB::table($table)->insert($row);
        }
    }
}
