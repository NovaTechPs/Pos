@php
    $arabic = new \ArPHP\I18N\Arabic();
    $ar = static function (mixed $value) use ($arabic): string {
        $text = (string) ($value ?? '');

        return $text === '' ? '' : $arabic->utf8Glyphs($text, 150, true, true);
    };
    $subtotal = $items->sum(fn ($item) => (float) $item->subtotal);
    $remaining = max(0, (float) $purchase->total - (float) $purchase->paid_amount);
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 12mm; }
        body { direction: rtl; color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        .header { text-align: center; border-bottom: 2px solid #1e293b; padding-bottom: 14px; margin-bottom: 16px; }
        .store { font-size: 20px; font-weight: bold; }
        .title { margin-top: 5px; font-size: 16px; font-weight: bold; }
        .info, .items { width: 100%; border-collapse: collapse; }
        .info { margin-bottom: 16px; }
        .info td { padding: 5px; vertical-align: top; }
        .items th { background: #1e293b; color: #fff; padding: 8px; text-align: center; }
        .items td { border: 1px solid #cbd5e1; padding: 8px; }
        .items .number { direction: ltr; text-align: center; }
        .totals { width: 45%; margin-top: 16px; margin-right: auto; border-collapse: collapse; }
        .totals td { border: 1px solid #cbd5e1; padding: 8px; }
        .totals .final { background: #e0e7ff; font-size: 13px; font-weight: bold; }
        .footer { margin-top: 30px; border-top: 1px dashed #94a3b8; padding-top: 10px; text-align: center; color: #475569; }
    </style>
</head>
<body>
    <div class="header">
        <div class="store">{{ $ar($storeName) }}</div>
        <div class="title">{{ $ar('فاتورة مشتريات') }}</div>
    </div>
    <table class="info">
        <tr>
            <td><strong>{{ $ar('رقم الفاتورة:') }}</strong> <span dir="ltr">{{ $purchase->reference_number }}</span></td>
            <td><strong>{{ $ar('التاريخ:') }}</strong> <span dir="ltr">{{ \Carbon\Carbon::parse($purchase->created_at)->format('Y-m-d H:i') }}</span></td>
        </tr>
        <tr><td colspan="2"><strong>{{ $ar('المورد:') }}</strong> {{ $ar($supplierName) }}</td></tr>
    </table>
    <table class="items">
        <thead><tr><th>#</th><th>{{ $ar('الصنف') }}</th><th>{{ $ar('الكمية') }}</th><th>{{ $ar('التكلفة') }}</th><th>{{ $ar('الإجمالي') }}</th></tr></thead>
        <tbody>
            @foreach ($items as $index => $item)
                <tr>
                    <td class="number">{{ $index + 1 }}</td>
                    <td>{{ $ar($item->product_name ?: 'مادة غير محددة') }}</td>
                    <td class="number">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="number">{{ number_format((float) $item->unit_cost, 2) }}</td>
                    <td class="number">{{ number_format((float) $item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>{{ $ar('المجموع') }}</td><td class="number">{{ number_format($subtotal, 2) }}</td></tr>
        <tr class="final"><td>{{ $ar('الإجمالي النهائي') }}</td><td class="number">{{ number_format((float) $purchase->total, 2) }}</td></tr>
        <tr><td>{{ $ar('المدفوع') }}</td><td class="number">{{ number_format((float) $purchase->paid_amount, 2) }}</td></tr>
        <tr><td>{{ $ar('المتبقي') }}</td><td class="number">{{ number_format($remaining, 2) }}</td></tr>
    </table>
    <div class="footer">{{ $ar('تم إنشاء الفاتورة بواسطة WaslaPOS') }}</div>
</body>
</html>
