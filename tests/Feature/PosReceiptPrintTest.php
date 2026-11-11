<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

test('POS receipt groups matching product prices and keeps different prices separate', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Receipt store']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    $product = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'Same product']);
    $order = Order::query()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'created_by' => $user->id,
        'invoice_number' => 'PRICE-GROUPS', 'type' => 'pos', 'status' => 'completed',
        'subtotal' => 55, 'total' => 55, 'paid_amount' => 55,
    ]);

    foreach ([[1, 10], [2, 12.5], [2, 10]] as [$quantity, $price]) {
        $order->items()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'quantity' => $quantity, 'unit_price' => $price, 'total_price' => $quantity * $price,
        ]);
    }

    $component = Livewire::test('pages::tenant.pos.sales')
        ->set('currentInvoiceId', $order->id)
        ->call('printReceipt')
        ->assertDispatched('print-receipt');
    $receipt = $component->get('receipt');

    expect($receipt['items'])->toHaveCount(2)
        ->and(array_column($receipt['items'], 'price'))->toBe(['10.00', '12.50'])
        ->and(array_column($receipt['items'], 'qty'))->toBe([3.0, 2.0])
        ->and(array_column($receipt['items'], 'total'))->toBe(['30.00', '25.00'])
        ->and($receipt['total_amount'])->toBe('55.00')
        ->and($order->items()->count())->toBe(3);

    $component->call('printReceipt')->assertSet('receipt', $receipt);
});

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

test('thermal receipt matches the shop layout for a walk-in sale', function () {
    $html = posReceiptPrintView([
        'items' => [
            ['id' => 22, 'name' => 'مشط صابون معدن طبقتين', 'qty' => 1, 'price' => '70.00', 'total' => '70.00'],
            ['id' => 35, 'name' => 'سطل ماء بلاستيك', 'qty' => 2, 'price' => '5.00', 'total' => '10.00'],
        ],
        'total_qty' => 3, 'subtotal' => '80.00', 'discount' => '0.00', 'total_amount' => '80.00', 'paid' => '100.00', 'change' => '20.00',
    ]);
    $summary = substr($html, strpos($html, 'data-invoice-balance-summary'));
    $summary = substr($summary, 0, strpos($summary, 'barcode-section'));

    expect($html)->toContain('رقم: <b dir="ltr">INV-1001</b>', 'التاريخ:', 'الكاشير: الموظف', '<th style="width:47%">الصنف</th>', '<th style="width:9%">ك</th>',
        'عدد الأصناف: <b>2</b>', 'إجمالي الكمية: <b>3</b>', 'item-name-text')
        ->and(strpos($html, '<td>1</td>'))->toBeLessThan(strpos($html, '<td>2</td>'))
        ->and($summary)->toContain('المجموع :', '80.00', 'الصافي للدفع', 'net-box')
        ->not->toContain('الخصم :', 'دفعة :', 'المدفوع :', 'المتبقي :', 'الباقي :', 'رصيد سابق', 'رصيد جديد', '100.00');
});

test('thermal receipt itemizes delivery before the black payable total', function (mixed $delivery, string $formatted): void {
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

test('thermal receipt prints delivery details without the delivery company', function (): void {
    $html = posReceiptPrintView([
        'delivery_fee' => '15.00',
        'delivery' => ['name' => 'سامي', 'phone' => '0599000000', 'address' => 'الخليل - رأس الجورة', 'notes' => 'اتصل قبل الوصول'],
    ]);

    expect($html)->toContain('data-receipt-delivery', 'معلومات التوصيل', 'الاسم: سامي', '0599000000', 'العنوان: الخليل - رأس الجورة', 'ملاحظة التوصيل: اتصل قبل الوصول')
        ->not->toContain('شركة التوصيل');
});

test('thermal receipt shows the previous and new customer balance', function () {
    $html = posReceiptPrintView([
        'paid' => '50.00',
        'customer_name' => 'أبو أحمد',
        'summary' => ['total' => 69.0, 'discount' => 0.0, 'payment' => 50.0, 'previous_balance' => 100.0, 'new_balance' => 119.0],
    ]);

    expect($html)->toContain('الزبون: أبو أحمد', 'دفعة :', '50.00', 'رصيد سابق :', '100.00', 'رصيد جديد :', '119.00')
        ->not->toContain('المتبقي :');
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
    expect($html)->toContain('750.00', 'عدد الأصناف: <b>75</b>', 'break-inside: avoid;');
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
