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
    $text = 'جميع الحقوق محفوظة © '.now()->year.' — WaslaPOS';
    $shapedText = (new Arabic)->utf8Glyphs($text, 150, true, true);

    $shapedStore = (new Arabic)->utf8Glyphs($storeName, 150, true, true);

    expect($html)->toContain(e($shapedStore))->toContain(e($shapedText))->toContain('20.00')
        ->and(strpos($html, e($shapedStore)))->toBeLessThan(strpos($html, 'data-invoice-copyright'))
        ->and(substr_count($html, 'data-invoice-copyright'))->toBe(1);
})->with(['متجر ياسمين', 'متجر آخر']);
