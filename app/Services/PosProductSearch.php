<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

class PosProductSearch
{
    private const LETTERS = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ـ' => '',
        'َ' => '', 'ً' => '', 'ُ' => '', 'ٌ' => '', 'ِ' => '', 'ٍ' => '', 'ْ' => '', 'ّ' => '', 'ٰ' => ''];

    private const DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    public function normalize(string $value): string
    {
        $value = strtr(mb_strtolower($value), self::LETTERS + self::DIGITS);

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
        $name = $this->normalizedColumn('products.name', $query->getGrammar() instanceof PostgresGrammar);
        $number = 'LOWER(products.product_number)';
        $barcode = 'EXISTS (SELECT 1 FROM product_barcodes AS search_barcodes WHERE search_barcodes.product_id = products.id AND search_barcodes.tenant_id = ? AND LOWER(search_barcodes.barcode)';
        $pattern = strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $query->where(function (Builder $query) use ($search, $name, $number, $barcode, $pattern, $tenantId): void {
            $query->where(function (Builder $query) use ($name, $pattern): void {
                foreach (explode(' ', $pattern) as $keyword) {
                    $query->whereRaw("{$name} LIKE ? ESCAPE '!'", ['%'.$keyword.'%']);
                }
            })->orWhereRaw("{$number} LIKE ? ESCAPE '!'", ['%'.$pattern.'%'])
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
    private function normalizedColumn(string $column, bool $postgres = false): string
    {
        if ($postgres) {
            return "TRANSLATE(LOWER({$column}), 'أإآٱىةـًٌٍَُِّْٰ', 'اااايه')";
        }
        $expression = "LOWER({$column})";
        foreach (self::LETTERS as $from => $to) {
            $expression = "REPLACE({$expression}, '{$from}', '{$to}')";
        }

        return $expression;
    }
}
