<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StockMovementContext
{
    /** @var array<int, array<string, mixed>> */
    private static array $stack = [];

    public static function reference(string $type, int $id, callable $callback, ?string $reference = null): mixed
    {
        self::$stack[] = ['stock_source_type' => $type, 'stock_source_id' => $id, 'stock_source_reference' => $reference];
        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /** @return array<string, mixed> */
    public static function referenceAttributes(string $type, int $id, ?string $reference = null): array
    {
        return self::reference($type, $id, fn (): array => self::attributes(), $reference);
    }

    public static function forDocument(Model $document, callable $callback, ?string $type = null): mixed
    {
        self::$stack[] = ['stock_source_type' => $type ?? match ($document->getTable()) {
            'orders' => $document->getAttribute('type') === 'return' ? 'sales_return' : 'order',
            'purchases' => 'purchase', 'purchase_returns' => 'purchase_return', 'stock_transfers' => 'stock_transfer',
            default => 'manual',
        }, 'stock_source_id' => $document->getKey(),
            'stock_source_reference' => $document->invoice_number ?? $document->reference_number ?? $document->transfer_number ?? null];
        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /** @return array<string, mixed> */
    public static function attributes(): array
    {
        return (self::$stack === [] ? ['stock_source_type' => 'manual', 'stock_source_id' => null, 'stock_source_reference' => null] : self::$stack[array_key_last(self::$stack)])
            + ['stock_operation_uuid' => (string) Str::uuid(), 'stock_actor_id' => auth()->id()];
    }
}
