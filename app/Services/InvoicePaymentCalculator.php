<?php

namespace App\Services;

use DomainException;
use InvalidArgumentException;

/** Pure invoice payment arithmetic. No database writes or balance mutations. */
final class InvoicePaymentCalculator
{
    public static function calculate(string $total, string $paid): array
    {
        $totalCents = self::cents($total);
        $paidCents = self::cents($paid);

        if ($totalCents < 0 || $paidCents < 0) {
            throw new DomainException('Invoice total and paid amount must be non-negative.');
        }
        if ($paidCents > $totalCents) {
            throw new DomainException('Paid amount exceeds invoice total; record an advance or refund separately.');
        }

        return [
            'total' => self::money($totalCents),
            'paid_amount' => self::money($paidCents),
            'remaining' => self::money($totalCents - $paidCents),
            'payment_status' => $paidCents === 0 ? 'unpaid' : ($paidCents === $totalCents ? 'paid' : 'partial'),
        ];
    }

    public static function initialPayment(string $total, string $mode, ?string $partialAmount = null): array
    {
        $paid = match ($mode) {
            'cash' => $total,
            'credit' => '0',
            'partial' => $partialAmount ?? throw new InvalidArgumentException('Partial payment amount is required.'),
            default => throw new InvalidArgumentException('Unsupported payment mode.'),
        };
        $result = self::calculate($total, $paid);
        if ($mode === 'partial' && ($result['payment_status'] !== 'partial')) {
            throw new DomainException('Partial payment must be greater than zero and less than the invoice total.');
        }
        return $result;
    }

    private static function cents(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('Invalid monetary amount (use at most two decimals).');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');
        if (strlen($whole) > 12) {
            throw new InvalidArgumentException('Amount too large.');
        }
        return ((int) $whole * 100) + (int) $fraction;
    }

    private static function money(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
