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
        $query->where(function (Builder $q) use ($prefix, $search, $tenantId) {
            $q->whereRaw("products.search_name LIKE ? ESCAPE '!'", [$prefix])
                ->orWhereRaw("products.product_number LIKE ? ESCAPE '!'", [$prefix])
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
            $query->whereRaw("products.search_name LIKE ? ESCAPE '!'", ['%'.$this->escapeLike($word).'%']);
        }
    }
}
