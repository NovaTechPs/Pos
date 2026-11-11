<?php

use App\Models\Order;
use ArPHP\I18N\Arabic;
use Illuminate\Support\Facades\Blade;

test('invoice copyright includes the company and current year in compact print styling', function (): void {
    $this->travelTo(now()->setDate(2027, 1, 1));

    $html = Blade::render('<x-invoice-copyright />');

    expect($html)->toContain('جميع الحقوق محفوظة © 2027 — WaslaPOS')
        ->toContain('font-size: 9px')->toContain('font-weight: 400')
        ->toContain('page-break-inside: avoid');
});

test('invoice PDF renders the store name above the company copyright without changing totals', function (string $storeName): void {
    $order = new Order(['invoice_number' => 'TEST-001', 'subtotal' => 20, 'total' => 20, 'discount' => 0, 'paid_amount' => 20]);
    $order->setRelation('items', collect());
    $order->setRelation('user', null);
    $order->setRelation('tenant', null);

    $html = view('pages.tenant.pos.partials.invoice-pdf', compact('order', 'storeName'))->render();
    // Latin digits are kept and the Latin part is placed left of the shaped Arabic words.
    $shapedText = '© '.now()->year.' — WaslaPOS  '.(new Arabic)->utf8Glyphs('جميع الحقوق محفوظة', 150, false, true);
    $shapedStore = (new Arabic)->utf8Glyphs($storeName, 150, false, true);

    expect($html)->toContain(e($shapedStore))->toContain(e($shapedText))->toContain('20.00')
        ->and(strpos($html, e($shapedStore)))->toBeLessThan(strpos($html, 'data-invoice-copyright'))
        ->and(substr_count($html, 'data-invoice-copyright'))->toBe(1);
})->with(['متجر ياسمين', 'متجر آخر']);

test('invoice PDF prints the amount received and the customer balance after an overpayment', function (): void {
    $order = new Order(['invoice_number' => 'PAY-190', 'subtotal' => 166.5, 'total' => 166.5, 'discount' => 0, 'paid_amount' => 166.5]);
    $order->setAttribute('collected_amount', 190);
    $order->setRelation('items', collect());
    $order->setRelation('user', null);
    $order->setRelation('tenant', null);
    $storeName = 'متجر الاختبار';
    $accountBalance = -23.5;

    $html = view('pages.tenant.pos.partials.invoice-pdf', compact('order', 'storeName', 'accountBalance'))->render();
    $arabic = new Arabic;

    // Walk-in invoice: only total, discount and the payment kept on the invoice (change is not a payment).
    expect($html)->toContain('166.50')->not->toContain('190.00')
        ->toContain($arabic->utf8Glyphs('المجموع', 150, false, true))
        ->toContain($arabic->utf8Glyphs('دفعة', 150, false, true))
        ->not->toContain($arabic->utf8Glyphs('المبلغ المستلم من العميل', 150, false, true))
        ->not->toContain($arabic->utf8Glyphs('المتبقي من الفاتورة', 150, false, true))
        ->not->toContain($arabic->utf8Glyphs('رصيد سابق', 150, false, true));
});
