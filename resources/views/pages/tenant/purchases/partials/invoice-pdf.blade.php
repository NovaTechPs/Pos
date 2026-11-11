@php
    /*
     * Dompdf lays tables out left-to-right, so every row is written in visual
     * order (left cell first) to read correctly from right to left in Arabic.
     */
    $arabic = new \ArPHP\I18N\Arabic();

    /** Shapes Arabic text (Latin digits kept) and wraps long text at $width characters. */
    $ar = static function (mixed $value, int $width = 150) use ($arabic): string {
        $text = (string) ($value ?? '');

        return $text === '' ? '' : nl2br(e($arabic->utf8Glyphs($text, $width, false, true)));
    };
    $subtotal = $items->sum(fn ($item) => (float) $item->subtotal);
    $totalQuantity = $items->sum(fn ($item) => (float) $item->quantity);
    $remaining = max(0, (float) $purchase->total - (float) $purchase->paid_amount);
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    @isset($documentTitle)<title>{{ $documentTitle }}</title>@endisset
    <style>
        @page { margin: 12mm 12mm 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
        table { border-collapse: collapse; }
        .num { direction: ltr; text-align: center; font-family: DejaVu Sans Mono, DejaVu Sans, monospace; }

        .head { width: 100%; border-bottom: 3px solid #1e293b; margin-bottom: 12px; }
        .head td { vertical-align: bottom; padding-bottom: 10px; }
        .store { font-size: 20px; font-weight: bold; text-align: right; }
        .doc-title { margin-top: 4px; font-size: 14px; font-weight: bold; color: #1d4ed8; text-align: right; }
        .doc-box { border: 1px solid #cbd5e1; }
        .doc-box td { padding: 4px 8px; }
        .doc-box .label { text-align: right; color: #475569; font-weight: bold; }

        .party { width: 100%; margin-bottom: 12px; border: 1px solid #cbd5e1; }
        .party td { padding: 6px 8px; text-align: right; }
        .party .label { width: 18%; background: #f1f5f9; color: #334155; font-weight: bold; }

        .items { width: 100%; }
        .items th { background: #1e293b; color: #ffffff; padding: 7px 6px; font-weight: bold; text-align: center; border: 1px solid #1e293b; }
        .items td { padding: 6px; border: 1px solid #cbd5e1; vertical-align: middle; }
        .items tr.alt td { background: #f8fafc; }
        .items td.name { text-align: right; }

        .bottom { width: 100%; margin-top: 12px; }
        .bottom > tbody > tr > td { vertical-align: top; }
        .totals, .stats { width: 100%; border: 1px solid #cbd5e1; }
        .totals td, .stats td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        .totals .label, .stats .label { text-align: right; color: #334155; }
        .totals tr.final td { background: #047857; color: #ffffff; font-size: 14px; font-weight: bold; border-bottom: 0; }
        .notes { margin-top: 10px; border: 1px dashed #94a3b8; padding: 8px; text-align: right; }
        .footer { margin-top: 18px; text-align: center; color: #64748b; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width: 45%;">
                <table class="doc-box" style="width: 100%;">
                    <tr>
                        <td class="num">{{ $purchase->reference_number }}</td>
                        <td class="label">{!! $ar('رقم الفاتورة') !!}</td>
                    </tr>
                    <tr>
                        <td class="num">{{ \Carbon\Carbon::parse($purchase->invoice_date ?: $purchase->created_at)->format('Y-m-d') }}</td>
                        <td class="label">{!! $ar('التاريخ') !!}</td>
                    </tr>
                </table>
            </td>
            <td style="width: 55%;">
                <div class="store">{!! $ar($storeName) !!}</div>
                <div class="doc-title">{!! $ar('فاتورة مشتريات') !!}</div>
            </td>
        </tr>
    </table>

    <table class="party">
        <tr>
            <td style="width: 82%;">{!! $ar($supplierName) !!}</td>
            <td class="label">{!! $ar('المورد') !!}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 15%;">{!! $ar('المجموع') !!}</th>
                <th style="width: 13%;">{!! $ar('التكلفة') !!}</th>
                <th style="width: 11%;">{!! $ar('الكمية') !!}</th>
                <th>{!! $ar('اسم الصنف') !!}</th>
                <th style="width: 15%;">{!! $ar('رقم الصنف') !!}</th>
                <th style="width: 6%;">#</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr class="{{ $loop->even ? 'alt' : '' }}">
                    <td class="num">{{ number_format((float) $item->subtotal, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->unit_cost, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="name">{!! $ar($item->product_name ?: 'مادة غير محددة', 45) !!}</td>
                    <td class="num">{{ ($item->product_number ?? null) ?: ($item->product_id ?? '—') }}</td>
                    <td class="num">{{ $loop->iteration }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="bottom">
        <tr>
            <td style="width: 52%;">
                <table class="totals">
                    <tr><td class="num">{{ number_format($subtotal, 2) }}</td><td class="label">{!! $ar('المجموع') !!}</td></tr>
                    <tr class="final"><td class="num">{{ number_format((float) $purchase->total, 2) }}</td><td class="label" style="color: #ffffff;">{!! $ar('الإجمالي النهائي') !!}</td></tr>
                    <tr><td class="num">{{ number_format((float) $purchase->paid_amount, 2) }}</td><td class="label">{!! $ar('المدفوع') !!}</td></tr>
                    <tr><td class="num">{{ number_format($remaining, 2) }}</td><td class="label">{!! $ar('المتبقي') !!}</td></tr>
                    @if (isset($accountBalance))
                        <tr><td class="num">{{ number_format((float) $accountBalance, 2) }}</td><td class="label">{!! $ar('رصيد الحساب الحالي بعد الفاتورة') !!}</td></tr>
                    @endif
                </table>
            </td>
            <td style="width: 8%;"></td>
            <td style="width: 40%;">
                <table class="stats">
                    <tr><td class="num">{{ $items->count() }}</td><td class="label">{!! $ar('عدد الأصناف') !!}</td></tr>
                    <tr><td class="num">{{ number_format($totalQuantity, 2) }}</td><td class="label">{!! $ar('إجمالي الكمية') !!}</td></tr>
                </table>

                @if (! empty($purchase->notes))
                    <div class="notes">
                        <strong>{!! $ar('ملاحظات') !!}</strong><br>
                        {!! $ar($purchase->notes, 55) !!}
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <div class="footer">WaslaPOS {!! $ar('تم إنشاء الفاتورة بواسطة') !!}</div>
</body>
</html>
