<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

class PosProductSearch
{
    private const LETTERS = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ـ' => '',
        'َ' => '', 'ً' => '', 'ُ' => '', 'ٌ' => '', 'ِ' => '', 'ٍ' => '', 'ْ' => '', 'ّ' => '', 'ٰ' => '',
    ];

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strtr(mb_strtolower($value), self::LETTERS + self::DIGITS)) ?? '');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    // Fast, portable first pass: prefix matches. Compatible with MySQL and PostgreSQL.
    public function apply(Builder $query, string $search, int $tenantId): void
    {
        $search = $this->normalize($search);
        if ($search === '' || mb_strlen($search) > 120) {
            $query->whereRaw('1 = 0');

            return;
        }
        $prefix = $this->escapeLike($search).'%';
        // Any part of the item number, e.g. "22" or "00000022" finds I00000022.
        $numberContains = '%'.$this->escapeLike($search).'%';
        $query->where(function (Builder $q) use ($prefix, $numberContains, $search, $tenantId) {
            $q->whereRaw("products.search_name LIKE ? ESCAPE '!'", [$prefix])
                ->orWhereRaw("LOWER(products.product_number) LIKE ? ESCAPE '!'", [$numberContains])
                ->orWhereExists(function (Builder $b) use ($tenantId, $prefix) {
                    $b->selectRaw('1')->from('product_barcodes as pb')
                        ->whereColumn('pb.product_id', 'products.id')
                        ->where('pb.tenant_id', $tenantId)
                        ->whereRaw("pb.barcode LIKE ? ESCAPE '!'", [$prefix]);
                });
            if (ctype_digit($search) && strlen($search) < 18) {
                $q->orWhere('products.id', (int) $search);
            }
        });
    }

    /**
     * Adds an OR condition matching barcodes that contain the typed digits anywhere,
     * e.g. the last 5 digits of a barcode. Only digit searches of 3+ characters qualify
     * so plain word searches never pay for the contains scan.
     */
    public function orWhereBarcodeContains(Builder $query, string $search, int $tenantId): void
    {
        $search = $this->normalize($search);
        if (! preg_match('/^[0-9]{3,40}$/', $search)) {
            return;
        }

        $pattern = '%'.$this->escapeLike($search).'%';
        $query->orWhereExists(function (Builder $barcodes) use ($tenantId, $pattern): void {
            $barcodes->selectRaw('1')->from('product_barcodes as pbc')
                ->whereColumn('pbc.product_id', 'products.id')
                ->where('pbc.tenant_id', $tenantId)
                ->whereRaw("pbc.barcode LIKE ? ESCAPE '!'", [$pattern]);
        });
    }

    // Fallback only when the prefix search returned nothing.
    public function applyContains(Builder $query, string $search): void
    {
        $search = $this->normalize($search);
        if ($search === '' || mb_strlen($search) > 120) {
            $query->whereRaw('1 = 0');

            return;
        }
        $words = explode(' ', $search);
        if (count($words) > 8) {
            $query->whereRaw('1 = 0');

            return;
        }
        foreach ($words as $word) {
            if (ctype_digit($word)) {
                $number = $this->escapeLike($word);
                $query->where(function (Builder $numberQuery) use ($number): void {
                    $numberQuery->where('products.search_name', $number)
                        ->orWhereRaw("products.search_name LIKE ? ESCAPE '!'", [$number.' %'])
                        ->orWhereRaw("products.search_name LIKE ? ESCAPE '!'", ['% '.$number.' %'])
                        ->orWhereRaw("products.search_name LIKE ? ESCAPE '!'", ['% '.$number]);
                });
            } else {
                $query->whereRaw("products.search_name LIKE ? ESCAPE '!'", ['%'.$this->escapeLike($word).'%']);
            }
        }
    }

    /** Puts a product whose barcode or product number equals the search first. */
    public function applyExactMatchOrdering(Builder $query, string $search, int $tenantId): void
    {
        $search = $this->normalize($search);
        if ($search === '') {
            return;
        }

        $query
            ->orderByRaw('CASE WHEN EXISTS (
                SELECT 1 FROM product_barcodes AS ranked_barcodes
                WHERE ranked_barcodes.product_id = products.id
                    AND ranked_barcodes.tenant_id = ?
                    AND ranked_barcodes.barcode = ?
            ) THEN 0 ELSE 1 END', [$tenantId, $search])
            ->orderByRaw('CASE WHEN LOWER(COALESCE(products.product_number, \'\')) = LOWER(?) THEN 0 ELSE 1 END', [$search]);
    }

    /** Orders products from the highest to the lowest stock in the branch; products without a branch row count as zero. */
    public function applyBranchStockOrdering(Builder $query, ?int $branchId): void
    {
        $query->orderByRaw('COALESCE((
            SELECT bp_sort.stock_quantity FROM branch_products AS bp_sort
            WHERE bp_sort.product_id = products.id AND bp_sort.branch_id = ?
            LIMIT 1
        ), 0) DESC', [$branchId]);
    }

    public function applyRelevanceOrdering(Builder $query, string $search, int $tenantId): void
    {
        $search = $this->normalize($search);
        if ($search === '') {
            return;
        }

        $prefix = $this->escapeLike($search).'%';
        $this->applyExactMatchOrdering($query, $search, $tenantId);
        $query
            ->orderByRaw("CASE WHEN LOWER(COALESCE(products.product_number, '')) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$prefix])
            ->orderByRaw('CASE WHEN EXISTS (
                SELECT 1 FROM product_barcodes AS ranked_barcodes
                WHERE ranked_barcodes.product_id = products.id
                    AND ranked_barcodes.tenant_id = ?
                    AND ranked_barcodes.barcode LIKE ? ESCAPE \'!\'
            ) THEN 0 ELSE 1 END', [$tenantId, $prefix])
            ->orderByRaw('CASE WHEN products.search_name = ? THEN 0 ELSE 1 END', [$search])
            ->orderByRaw("CASE WHEN products.search_name LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$prefix]);
    }
}
