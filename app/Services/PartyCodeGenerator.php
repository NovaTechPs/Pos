<?php

namespace App\Services;

use Brick\Math\BigInteger;
use Illuminate\Database\Query\Builder;

class PartyCodeGenerator
{
    public function nextCode(Builder $parties): string
    {
        $highestCode = BigInteger::zero();

        foreach ($parties->select('code')->whereNotNull('code')->cursor() as $party) {
            if (! is_string($party->code) || ! ctype_digit($party->code)) {
                continue;
            }

            $numericCode = BigInteger::of($party->code);
            if ($numericCode->isGreaterThan($highestCode)) {
                $highestCode = $numericCode;
            }
        }

        return (string) $highestCode->plus(1);
    }
}
