@php
    /*
     * Dompdf lays tables out left-to-right, so every row is written in visual
     * order (left cell first) to read correctly from right to left in Arabic.
     */
    $arabic = new \ArPHP\I18N\Arabic();

    $receivedAmount = max(0, (float) ($order->collected_amount ?? $order->paid_amount ?? 0));
    $summary = \App\Services\InvoiceBalanceSummary::forOrder($order, $receivedAmount);

    /** Shapes Arabic text (Latin digits kept) and wraps long text at $width characters. */
    $ar = function ($text, int $width = 150) use ($arabic): string {
        $text = (string) ($text ?? '');

        return $text === '' ? '' : nl2br(e($arabic->utf8Glyphs($text, $width, false, true)));
    };
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
        .muted { color: #64748b; }

        .head { width: 100%; border-bottom: 3px solid #1e293b; margin-bottom: 12px; }
        .head td { vertical-align: bottom; padding-bottom: 10px; }
        .store { font-size: 20px; font-weight: bold; text-align: right; }
        .doc-title { margin-top: 4px; font-size: 14px; font-weight: bold; color: #1d4ed8; text-align: right; }
        .doc-box { border: 1px solid #cbd5e1; border-radius: 6px; }
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
        .totals { width: 100%; border: 1px solid #cbd5e1; }
        .totals td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        .totals .label { text-align: right; color: #334155; }
        .totals tr.final td { background: #047857; color: #ffffff; font-size: 14px; font-weight: bold; border-bottom: 0; }
        .stats { width: 100%; border: 1px solid #cbd5e1; }
        .stats td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        .stats .label { text-align: right; color: #334155; }
        .notes { margin-top: 10px; border: 1px dashed #94a3b8; padding: 8px; text-align: right; }
        .footer { margin-top: 18px; text-align: center; color: #64748b; }
    </style>
</head>
<body>

    {{-- الرأس: معلومات الفاتورة يساراً واسم المتجر يميناً --}}
    <table class="head">
        <tr>
            <td style="width: 45%;">
                <table class="doc-box" style="width: 100%;">
                    <tr>
                        <td class="num">{{ $order->invoice_number }}</td>
                        <td class="label">{!! $ar('رقم الفاتورة') !!}</td>
                    </tr>
                    <tr>
                        <td class="num">{{ $order->invoice_date?->format('Y-m-d') ?? optional($order->created_at)->format('Y-m-d H:i') }}</td>
                        <td class="label">{!! $ar('التاريخ') !!}</td>
                    </tr>
                </table>
            </td>
            <td style="width: 55%;">
                <div class="store">{!! $ar($storeName ?? $order->branch?->name ?? 'المتجر') !!}</div>
                <div class="doc-title">{!! $ar($order->type === 'return' ? 'فاتورة مرتجع' : 'فاتورة مبيعات') !!}</div>
            </td>
        </tr>
    </table>

    {{-- الزبون والكاشير --}}
    <table class="party">
        <tr>
            <td style="width: 32%;">{!! $ar($order->user?->name ?? 'الكاشير') !!}</td>
            <td class="label">{!! $ar('الكاشير') !!}</td>
            <td style="width: 32%;">{!! $ar($order->customer_name ?: 'زبون عام') !!}</td>
            <td class="label">{!! $ar('الزبون') !!}</td>
        </tr>
    </table>

    {{-- الأصناف: من اليمين # ثم رقم الصنف ثم الاسم --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width: 15%;">{!! $ar('المجموع') !!}</th>
                <th style="width: 13%;">{!! $ar('السعر') !!}</th>
                <th style="width: 11%;">{!! $ar('الكمية') !!}</th>
                <th>{!! $ar('اسم الصنف') !!}</th>
                <th style="width: 15%;">{!! $ar('رقم الصنف') !!}</th>
                <th style="width: 6%;">#</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $index => $item)
                <tr class="{{ $loop->even ? 'alt' : '' }}">
                    <td class="num">{{ number_format((float) $item->total_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="name">{!! $ar(($item->product?->name ?? 'منتج غير محدد').($item->is_gift ? ' (هدية)' : ''), 45) !!}</td>
                    <td class="num">{{ $item->product?->product_number ?: ($item->product_id ?? '—') }}</td>
                    <td class="num">{{ $loop->iteration }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- تحت الفاتورة: المجموع، الخصم، دفعة، رصيد سابق، رصيد جديد فقط --}}
    <table class="bottom">
        <tr>
            <td style="width: 52%;">
                <table class="totals" data-invoice-balance-summary>
                    <tr><td class="num">{{ number_format($summary['total'], 2) }}</td><td class="label">{!! $ar('المجموع') !!}</td></tr>
                    <tr><td class="num">{{ number_format($summary['discount'], 2) }}</td><td class="label">{!! $ar('الخصم') !!}</td></tr>
                    <tr><td class="num">{{ number_format($summary['payment'], 2) }}</td><td class="label">{!! $ar('دفعة') !!}</td></tr>
                    @if ($summary['previous_balance'] !== null)
                        <tr><td class="num">{{ number_format($summary['previous_balance'], 2) }}</td><td class="label">{!! $ar('رصيد سابق') !!}</td></tr>
                        <tr class="final"><td class="num">{{ number_format($summary['new_balance'], 2) }}</td><td class="label" style="color: #ffffff;">{!! $ar('رصيد جديد') !!}</td></tr>
                    @endif
                </table>
            </td>
            <td style="width: 8%;"></td>
            <td style="width: 40%;">
                @if ($order->notes)
                    <div class="notes" style="margin-top: 0;">
                        <strong>{!! $ar('ملاحظات') !!}</strong><br>
                        {!! $ar($order->notes, 55) !!}
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <x-invoice-copyright :pdf="true" />
</body>
</html>
