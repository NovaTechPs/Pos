<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Payment vouchers are recorded from the desktop app and computers; on Android devices
 * (the mobile sales app) only receipt vouchers are offered, so payment vouchers are hidden there.
 */
class PaymentVoucherAccess
{
    public function available(?Request $request = null): bool
    {
        return ! str_contains((string) ($request ?? request())->userAgent(), 'Android');
    }
}
