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

test('thermal receipt shows partial payment and remaining balance', function () {
    $html = posReceiptPrintView([
        'paid' => '50.00',
    ]);

    expect($html)
        ->toContain('المدفوع :')
        ->toContain('المتبقي :')
        ->toContain('19.00');
});
