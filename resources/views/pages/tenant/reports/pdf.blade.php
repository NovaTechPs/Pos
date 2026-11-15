@php
    /*
     * Dompdf lays tables out left-to-right, so columns are written in reverse
     * order and Arabic text is shaped to read correctly from right to left.
     */
    $arabic = new \ArPHP\I18N\Arabic();
    $ar = fn ($text): string => ($text = (string) ($text ?? '')) === '' ? '' : e($arabic->utf8Glyphs($text, 400, false, true));
    $columns = array_reverse($definition['columns'], true);
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #17213d; }
        .head { width: 100%; border-bottom: 2px solid #124bfa; margin-bottom: 8px; }
        .head td { padding: 4px 0; }
        .store { font-size: 15px; font-weight: bold; text-align: right; }
        .title { font-size: 13px; font-weight: bold; color: #124bfa; text-align: right; }
        .meta { color: #475569; text-align: left; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .summary td { background: #eaf0ff; border: 2px solid #ffffff; padding: 5px; text-align: center; }
        .summary b { display: block; font-size: 11px; }
        .data { width: 100%; border-collapse: collapse; }
        .data th { background: #edf1fc; color: #263758; border: 1px solid #cbd5e1; padding: 5px; text-align: right; }
        .data td { border: 1px solid #e2e8f0; padding: 4px 5px; text-align: right; }
        .data tr:nth-child(even) td { background: #f8faff; }
        .note { color: #475569; text-align: right; margin: 2px 0; }
    </style>
</head>
<body>
    <table class="head"><tr>
        <td class="meta">{{ $from }} - {{ $to }}<br>{!! $ar($branchName) !!}</td>
        <td><div class="store">{!! $ar($tenantName) !!}</div><div class="title">{!! $ar($title) !!}</div></td>
    </tr></table>
    @if ($definition['summary'])
        <table class="summary"><tr>
            @foreach (array_reverse($definition['summary'], true) as $label => $value)
                <td>{!! $ar($label) !!}<b>{{ number_format((float) $value, 2) }}</b></td>
            @endforeach
        </tr></table>
    @endif
    <table class="data">
        <thead><tr>@foreach ($columns as $label)<th>{!! $ar($label) !!}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>@foreach ($columns as $key => $label)<td>{!! $ar(\App\Services\ReportingService::formatCell($key, $row->{$key} ?? null)) !!}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ max(1, count($columns)) }}" style="text-align:center">{!! $ar('لا توجد بيانات مطابقة.') !!}</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($truncated)
        <p class="note">{!! $ar('يعرض الملف أول '.number_format(\App\Http\Controllers\ReportPrintController::PDF_ROW_LIMIT).' سطر فقط؛ استخدم الطباعة أو تصدير Excel للتقرير كاملاً.') !!}</p>
    @endif
    <p class="note">{{ now()->format('Y-m-d H:i') }}</p>
</body>
</html>
