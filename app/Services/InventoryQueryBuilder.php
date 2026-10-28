<?php

namespace App\Services;

use App\Models\BranchProduct;
use Illuminate\Database\Eloquent\Builder;

/** @extends Builder<BranchProduct> */
class InventoryQueryBuilder extends Builder
{
    public function update(array $values): int
    {
        if (array_key_exists('stock_quantity', $values)) {
            $values = array_merge($values, StockMovementContext::attributes());
        }

        return parent::update($values);
    }

    public function increment(mixed $column, mixed $amount = 1, array $extra = []): int
    {
        if ($column === 'stock_quantity') {
            $extra = array_merge($extra, StockMovementContext::attributes());
        }

        return parent::increment($column, $amount, $extra);
    }

    public function decrement(mixed $column, mixed $amount = 1, array $extra = []): int
    {
        if ($column === 'stock_quantity') {
            $extra = array_merge($extra, StockMovementContext::attributes());
        }

        return parent::decrement($column, $amount, $extra);
    }

    public function incrementEach(array $columns, array $extra = []): int
    {
        if (array_key_exists('stock_quantity', $columns)) {
            $extra = array_merge($extra, StockMovementContext::attributes());
        }

        return parent::incrementEach($columns, $extra);
    }

    public function decrementEach(array $columns, array $extra = []): int
    {
        if (array_key_exists('stock_quantity', $columns)) {
            $extra = array_merge($extra, StockMovementContext::attributes());
        }

        return parent::decrementEach($columns, $extra);
    }
}
