<div id="thermal-receipt" class="hidden print:block text-black bg-white p-2 font-mono text-xs w-[80mm] mx-auto" dir="rtl">
    @if (!empty($receipt))
        <div class="text-center font-bold mb-2">
            <h2 class="text-base font-black">{{ $receipt['store_name'] }}</h2>
            <p class="text-[10px]">{{ $receipt['copy_type'] }}</p>
            <p class="text-[10px]">رقم الفاتورة: {{ $receipt['invoice_no'] }}</p>
            <p class="text-[10px]">{{ $receipt['date'] }} {{ $receipt['time'] }}</p>
        </div>
        <div class="border-b border-t border-black py-1 my-1 text-[10px]"><div class="flex justify-between"><span>الكاشير:</span><span class="font-bold">{{ $this->invoiceCreator }}</span></div></div>
        <table class="w-full text-right my-2 text-[10px] border-collapse"><thead><tr class="border-b border-black"><th>الصنف</th><th class="text-center">الكمية</th><th class="text-center">السعر</th><th class="text-left">الإجمالي</th></tr></thead><tbody>@foreach($receipt['items'] as $item)<tr><td class="font-bold">{{ $item['name'] }}</td><td class="text-center">{{ $item['qty'] }}</td><td class="text-center">{{ $item['price'] }}</td><td class="text-left font-bold">{{ $item['total'] }}</td></tr>@endforeach</tbody></table>
        <div class="border-t border-black pt-1 mt-1 text-[11px] space-y-0.5"><div class="flex justify-between"><span>مجموع الكميات:</span><span>{{ $receipt['total_qty'] }}</span></div><div class="flex justify-between"><span>المجموع:</span><span>{{ $receipt['total_amount'] }}</span></div><div class="flex justify-between font-black text-sm border-t border-black pt-1"><span>الصافي:</span><span>{{ $receipt['net_amount'] }}</span></div></div>
        <div class="text-center mt-4 pt-2 border-t border-dashed border-black text-[9px]"><p>{{ $receipt['notice'] }}</p><p>{{ $receipt['system_name'] }}</p></div>
    @endif
</div>
<iframe id="silent-print-frame" style="display:none;position:absolute;width:0;height:0;border:0"></iframe>
