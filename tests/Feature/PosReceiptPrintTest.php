<?php

function posReceiptPrintView(array $overrides = []): string
{
    $receipt = array_merge([
        'store_name' => 'المتجر',
        'notice' => 'شكراً لتعاملكم معنا',
        'copy_type' => 'فاتورة بيع',
        'invoice_no' => 'INV-1001',
        'date' => '2026/10/14',
        'time' => '10:30 AM',
        'cashier' => 'الموظف',
        'items' => [[
            'id' => 1,
            'name' => 'منتج تجريبي',
            'qty' => 1,
            'price' => '69.00',
            'total' => '69.00',
            'normal_total' => '69.00',
            'promotion_savings' => '0.00',
            'has_promotion' => false,
            'offer_label' => '',
        ]],
        'subtotal' => '69.00',
        'discount' => '0.00',
        'total_amount' => '69.00',
        'paid' => '69.00',
        'change' => '0.00',
        'currency' => 'ش.ض',
    ], $overrides);

    return view('pages.tenant.pos.partials.thermal-receipt', compact('receipt'))->render();
}

test('thermal receipt keeps equal payment details off the compact summary', function () {
    $html = posReceiptPrintView();

    expect($html)
        ->toContain('INV-1001')
        ->toContain('الصافي للدفع')
        ->not->toContain('المدفوع :')
        ->not->toContain('الباقي :');
});

test('thermal receipt itemizes delivery before the final payable total', function (mixed $delivery, string $formatted): void {
    $html = posReceiptPrintView([
        'items' => [['id' => 1, 'name' => 'طلب', 'qty' => 1, 'price' => '55.00', 'total' => '55.00']],
        'subtotal' => '55.00', 'delivery_fee' => $delivery,
        'total_amount' => number_format(55 + (float) str_replace(',', '', (string) $delivery), 2),
    ]);
    expect($html)->toContain('التوصيل :', $formatted, '55.00')
        ->and(strpos($html, 'التوصيل :'))->toBeLessThan(strpos($html, 'الصافي للدفع'));
})->with([[23, '23.00'], ['23.00', '23.00'], ['1,250.50', '1,250.50']]);

test('thermal receipt omits delivery when no fee was charged', function (mixed $delivery): void {
    expect(posReceiptPrintView(['delivery_fee' => $delivery]))->not->toContain('التوصيل :');
})->with([null, 0, '0.00']);

test('thermal receipt shows partial payment and remaining balance', function () {
    $html = posReceiptPrintView([
        'paid' => '50.00',
    ]);

    expect($html)
        ->toContain('المدفوع :')
        ->toContain('المتبقي :')
        ->toContain('19.00');
});

test('thermal receipt fits the printable roll area and uses lighter table text', function (): void {
    $html = posReceiptPrintView();

    expect($html)->toContain('width: 72mm !important;', 'max-width: 100% !important;',
        'position: static !important;', 'table-layout: fixed;', 'overflow-wrap: anywhere;',
        'body.printing-pos-receipt > :not(#thermal-receipt)', 'document.body.appendChild(receipt)',
        'receiptPlaceholder.replaceWith(receipt)', "window.addEventListener('afterprint', restoreReceipt)")
        ->not->toContain('width: 80mm', 'font-weight: bold;', 'size: 80mm auto');
});

test('long receipt preserves every item and its final payment details', function (): void {
    $items = collect(range(1, 75))->map(fn (int $index): array => [
        'id' => $index, 'name' => 'اسم صنف طويل للغاية للاختبار '.$index,
        'qty' => 1, 'price' => '10.00', 'total' => '10.00',
    ])->all();
    $html = posReceiptPrintView(['items' => $items, 'total_amount' => '750.00', 'paid' => '700.00']);

    foreach ($items as $item) {
        expect($html)->toContain($item['name']);
    }
    expect($html)->toContain('750.00', '700.00', '50.00', 'break-inside: avoid;');
});

test('long invoice barcode scales to the available receipt width without dropping bars', function (): void {
    $invoice = 'POS-2026-10-23-12345678901234567890';
    $html = posReceiptPrintView(['invoice_no' => $invoice]);
    preg_match('/viewBox="0 0 (\d+) 40"/', $html, $viewBox);
    preg_match_all('/<rect x="(\d+)" y="0" width="(\d+)"/', $html, $bars, PREG_SET_ORDER);

    expect($html)->toContain('preserveAspectRatio="xMidYMid meet"', $invoice);
    expect($bars)->not->toBeEmpty();
    $lastBar = end($bars);
    expect((int) $lastBar[1] + (int) $lastBar[2])->toBeLessThanOrEqual((int) $viewBox[1] - 10);
});

test('thermal receipt places the company copyright after the invoice barcode', function (): void {
    $html = posReceiptPrintView();

    expect($html)->toContain('جميع الحقوق محفوظة © '.now()->year.' — WaslaPOS')
        ->and(strpos($html, 'data-invoice-copyright'))->toBeGreaterThan(strpos($html, 'class="invoice-barcode-wrap"'));
});
