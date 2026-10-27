<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

class PosProductSearch
{
    private const LETTERS = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ـ' => '',
        'َ' => '', 'ً' => '', 'ُ' => '', 'ٌ' => '', 'ِ' => '', 'ٍ' => '', 'ْ' => '', 'ّ' => '', 'ٰ' => ''];

    public function normalize(string $value): string
    {
        $value = strtr(mb_strtolower($value), self::LETTERS + array_combine(
            mb_str_split('٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹'), str_split('01234567890123456789')));

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    public function apply(Builder $query, string $search, int $tenantId): void
    {
        $search = $this->normalize($search);
        if ($search === '') {
            return;
        }
        if (mb_strlen($search) > 120 || count(explode(' ', $search)) > 8) {
            $query->whereRaw('1 = 0');

            return;
        }
        $name = $this->normalizedColumn('products.name');
        $number = 'LOWER(products.product_number)';
        $barcode = 'EXISTS (SELECT 1 FROM product_barcodes AS search_barcodes WHERE search_barcodes.product_id = products.id AND search_barcodes.tenant_id = ? AND LOWER(search_barcodes.barcode)';
        $pattern = strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $query->where(function (Builder $query) use ($search, $name, $number, $barcode, $pattern, $tenantId): void {
            $query->where(function (Builder $query) use ($name, $pattern): void {
                foreach (explode(' ', $pattern) as $keyword) {
                    $query->whereRaw("{$name} LIKE ? ESCAPE '!'", ['%'.$keyword.'%']);
                }
            })->orWhereRaw("{$number} LIKE ? ESCAPE '!'", [$pattern.'%'])
                ->orWhereRaw("{$barcode} LIKE ? ESCAPE '!')", [$tenantId, $pattern.'%']);
            if (ctype_digit($search) && strlen($search) <= 18) {
                $query->orWhere('products.id', (int) $search);
            }
        });
        $productId = ctype_digit($search) && strlen($search) <= 18 ? (int) $search : 0;
        $query->orderByRaw("CASE WHEN {$barcode} = ?) THEN 0 WHEN {$number} = ? OR products.id = ? THEN 1 WHEN {$name} = ? THEN 2 WHEN {$name} LIKE ? ESCAPE '!' THEN 3 ELSE 4 END",
            [$tenantId, $search, $search, $productId, $search, $pattern.'%']);
    }

    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    private function normalizedColumn(string $column): string
    {
        $expression = "LOWER({$column})";
        foreach (self::LETTERS as $from => $to) {
            $expression = "REPLACE({$expression}, '{$from}', '{$to}')";
        }

        return $expression;
    }
}
