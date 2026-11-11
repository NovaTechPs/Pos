@php
    $arabic = new \ArPHP\I18N\Arabic();
    $ar = static fn (mixed $text): string => filled($text) ? $arabic->utf8Glyphs((string) $text, 150, true, true) : '';
    $title = $voucher->type === 'payment' ? 'سند صرف' : 'سند قبض';
    $method = match ($voucher->payment_method) {
        'cash' => 'نقدي', 'bank', 'bank_transfer' => 'تحويل بنكي',
        'card' => 'بطاقة', 'cheque', 'check' => 'شيك', default => $voucher->payment_method,
    };
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    @isset($documentTitle)<title>{{ $documentTitle }}</title>@endisset
    <style>
        @page { margin: 18mm; }
        body { font-family: DejaVu Sans, sans-serif; direction: rtl; font-size: 12px; color: #172033; }
        h1, h2 { text-align: center; }
        h1 { font-size: 22px; }
        h2 { font-size: 18px; margin-bottom: 26px; }
        table { width: 100%; border-collapse: collapse; }
        td { border: 1px solid #cbd5e1; padding: 12px; text-align: right; }
        .label { width: 36%; background: #f1f5f9; }
        .number { direction: ltr; }
        .amount { font-weight: bold; font-size: 18px; }
        .notes { margin-top: 24px; line-height: 1.8; }
        .signatures { margin-top: 60px; }
    </style>
</head>
<body>
    <h1>{{ $ar($voucher->branch?->name ?: $voucher->tenant?->name) }}</h1>
    <h2>{{ $ar($title) }}</h2>
    <table>
        <tr><td class="number">{{ $voucher->voucher_number }}</td><td class="label">{{ $ar('رقم السند') }}</td></tr>
        <tr><td class="number">{{ $voucher->payment_date?->format('Y-m-d') }}</td><td class="label">{{ $ar('التاريخ') }}</td></tr>
        <tr><td>{{ $ar($voucher->party?->name ?: '—') }}</td><td class="label">{{ $ar('الحساب') }}</td></tr>
        <tr><td>{{ $ar($method) }}</td><td class="label">{{ $ar('طريقة الدفع') }}</td></tr>
        <tr><td class="number amount">{{ number_format((float) $voucher->amount, 2) }}</td><td class="label">{{ $ar('المبلغ') }}</td></tr>
        @if ($voucher->party)
            <tr><td class="number">{{ number_format((float) $voucher->party->current_balance, 2) }}</td><td class="label">{{ $ar('رصيد الحساب الحالي بعد السند') }}</td></tr>
        @endif
        <tr><td>{{ $ar($voucher->creator?->name ?: '—') }}</td><td class="label">{{ $ar('أنشئ بواسطة') }}</td></tr>
    </table>
    @if ($voucher->notes)
        <div class="notes">{{ $ar('ملاحظات:') }}<br>{{ $ar($voucher->notes) }}</div>
    @endif
    <table class="signatures"><tr><td>{{ $ar('توقيع المستلم') }}</td><td>{{ $ar('توقيع المحاسب') }}</td></tr></table>
    <x-invoice-copyright :pdf="true" />
</body>
</html>
