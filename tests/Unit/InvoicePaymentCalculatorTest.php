<?php

namespace Tests\Unit;

use App\Services\InvoicePaymentCalculator;
use DomainException;
use PHPUnit\Framework\TestCase;

final class InvoicePaymentCalculatorTest extends TestCase
{
    public function test_cash_invoice(): void
    {
        $result = InvoicePaymentCalculator::initialPayment('500.00', 'cash');
        self::assertSame('paid', $result['payment_status']);
        self::assertSame('0.00', $result['remaining']);
    }

    public function test_credit_invoice(): void
    {
        $result = InvoicePaymentCalculator::initialPayment('500.00', 'credit');
        self::assertSame('unpaid', $result['payment_status']);
        self::assertSame('500.00', $result['remaining']);
    }

    public function test_partial_invoice(): void
    {
        $result = InvoicePaymentCalculator::initialPayment('500.00', 'partial', '200.00');
        self::assertSame('partial', $result['payment_status']);
        self::assertSame('300.00', $result['remaining']);
    }

    public function test_overpayment_rejected(): void
    {
        $this->expectException(DomainException::class);
        InvoicePaymentCalculator::calculate('100.00', '100.01');
    }
}
