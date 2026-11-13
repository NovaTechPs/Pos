<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Writes catalog rows downloaded from the central server into the desktop's local database.
 *
 * The central server is the reference for the catalog, so a local row with the same id is replaced by the
 * server's version even when it moved to another branch or product (for example after branches were merged).
 * Local rows that hold the server row's natural key under another id are stale copies and are removed.
 * Only a row of another tenant is refused, because that would mean the device mixes two stores.
 */
class LocalCatalogStore
{
    /** Columns besides `id` that identify a catalog row; a second local row with them is a stale copy. */
    private const NATURAL_KEYS = [
        'branch_products' => ['branch_id', 'product_id'],
        'product_barcodes' => ['tenant_id', 'barcode'],
        'categories' => ['tenant_id', 'name'],
        'parties' => ['tenant_id', 'code'],
    ];

    /** @var array<string, list<string>> */
    private array $columns = [];

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $preserved  Columns kept as they are locally when the row already exists.
     */
    public function store(string $table, array $row, int $tenantId, array $preserved = []): void
    {
        if ((int) ($row['tenant_id'] ?? 0) !== $tenantId || ! isset($row['id'])) {
            throw new RuntimeException('Unexpected catalog tenant or missing identifier.');
        }

        $existing = DB::table($table)->where('id', $row['id'])->lockForUpdate()->first();
        if ($existing && (int) $existing->tenant_id !== $tenantId) {
            throw new RuntimeException('Catalog identifier conflicts with another local tenant.');
        }

        $row = Arr::only(Arr::except($row, ['created_by', 'updated_by']), $this->columnsOf($table));
        $this->removeStaleCopies($table, $row);

        if (! $existing) {
            DB::table($table)->insert($row);

            return;
        }

        $movedToAnotherOwner = collect(['branch_id', 'product_id'])->contains(
            fn (string $column): bool => isset($row[$column], $existing->{$column}) && (int) $row[$column] !== (int) $existing->{$column});
        $incoming = $movedToAnotherOwner ? $row : Arr::except($row, $preserved);
        $changes = array_filter($incoming,
            fn (mixed $value, string $column): bool => ($value === null) !== ($existing->{$column} === null) || $value != $existing->{$column},
            ARRAY_FILTER_USE_BOTH);
        if ($changes !== []) {
            DB::table($table)->where('id', $row['id'])->update($changes);
        }
    }

    /** @param array<string, mixed> $row */
    private function removeStaleCopies(string $table, array $row): void
    {
        $key = self::NATURAL_KEYS[$table] ?? null;
        if ($key === null || collect($key)->contains(fn (string $column): bool => ! isset($row[$column]))) {
            return;
        }

        $stale = DB::table($table)->where(Arr::only($row, $key))->where('id', '!=', $row['id']);
        if (! $stale->exists()) {
            return;
        }

        try {
            DB::transaction(fn () => $stale->delete());
        } catch (QueryException $exception) {
            throw new RuntimeException("A stale local {$table} row is still referenced and cannot be replaced.", previous: $exception);
        }
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        return $this->columns[$table] ??= Schema::getColumnListing($table);
    }
}
