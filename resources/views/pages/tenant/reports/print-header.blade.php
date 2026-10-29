<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>{{ $title }} — {{ $tenantName }}</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;color:#172033;margin:24px;font-size:12px}h1{font-size:22px;margin:8px 0}header{border-bottom:2px solid #334155;padding-bottom:16px}table{width:100%;border-collapse:collapse;margin-top:18px}th,td{border:1px solid #cbd5e1;padding:8px;text-align:right}th{background:#f1f5f9}thead{display:table-header-group}tr{break-inside:avoid}footer{margin-top:20px;font-size:10px;color:#475569}.summary{display:flex;gap:20px;flex-wrap:wrap;padding:12px 0}.note{font-size:11px;color:#475569}.actions{margin-bottom:15px}@media print{.actions{display:none}body{margin:0;font-size:10px}th,td{padding:5px}@page{size:A4 landscape;margin:12mm}}
</style></head><body>
<div class="actions"><button type="button" onclick="window.print()">طباعة / حفظ PDF</button></div>
<header><strong>{{ $tenantName }}</strong><h1>{{ $title }}</h1><p>الفرع: {{ $branchName }} — من {{ $from }} إلى {{ $to }}</p></header>
<div class="summary">@foreach ($definition['summary'] as $label => $value)<span>{{ $label }}: <b>{{ number_format((float) $value, 2) }}</b></span>@endforeach</div>
@foreach ($definition['notes'] as $note)<p class="note">{{ $note }}</p>@endforeach
<table><thead><tr>@foreach ($definition['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
