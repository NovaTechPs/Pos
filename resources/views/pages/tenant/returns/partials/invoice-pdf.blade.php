@php
    $arabic = new \ArPHP\I18N\Arabic();
    $ar = fn ($text) => $arabic->utf8Glyphs((string) ($text ?? ''), 150, true, true);
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
@page { margin: 12mm; } body { font-family: 'DejaVu Sans', sans-serif; direction: rtl; text-align: right; font-size: 12px; color: #0f172a; } h1 {text-align:center} table {width:100%;border-collapse:collapse;margin:18px 0} th,td {border-bottom:1px solid #cbd5e1;padding:9px} th {background:#e2e8f0} .number {direction:ltr;text-align:center} .summary {width:60%;margin-left:auto} .notes {white-space:pre-line}
</style></head><body>
<h1>{{ $ar($kind === 'sale' ? 'مردود مبيعات' : 'مردود مشتريات') }}</h1>
<p>{{ $ar($branchName) }}</p>
<p>{{ $ar('رقم المردود') }}: {{ $kind === 'sale' ? $return->invoice_number : $return->reference_number }}</p>
<p>{{ $ar($party?->name) }} — {{ \Illuminate\Support\Carbon::parse($return->created_at)->format('Y-m-d') }}</p>
<table><thead><tr><th>{{ $ar('الإجمالي') }}</th><th>{{ $ar('السعر') }}</th><th>{{ $ar('الكمية') }}</th><th>{{ $ar('الصنف') }}</th></tr></thead><tbody>
@foreach($items as $item)<tr><td class="number">{{ number_format(abs((float) ($kind === 'sale' ? $item->total_price : $item->subtotal)), 2) }}</td><td class="number">{{ number_format((float) ($kind === 'sale' ? $item->unit_price : $item->unit_cost), 2) }}</td><td class="number">{{ number_format(abs((float) $item->quantity), 2) }}</td><td>{{ $ar($item->name) }}</td></tr>@endforeach
</tbody></table>
<table class="summary">
@foreach(['subtotal' => 'المجموع الفرعي', 'discount' => 'الخصم', 'tax_amount' => 'الضريبة المضمنة', 'total' => 'إجمالي المردود', 'paid_amount' => 'الدفعة'] as $field => $label)
@if($field !== 'tax_amount' || $showTax)<tr><td class="number">{{ number_format(abs((float) $return->$field), 2) }}</td><td>{{ $ar($label) }}</td></tr>@endif
@endforeach
<tr><td class="number">{{ number_format((float) ($party?->current_balance ?? 0), 2) }}</td><td>{{ $ar('رصيد الحساب الحالي') }}</td></tr>
</table><p class="notes">{{ $ar($return->notes) }}</p>
</body></html>
