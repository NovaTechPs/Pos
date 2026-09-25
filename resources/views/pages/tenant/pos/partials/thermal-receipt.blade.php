<div id="thermal-receipt" class="hidden w-[80mm] bg-white p-2 text-black print:block" dir="rtl">
    @if (!empty($receipt))
        <div class="text-center">
            <div class="text-base font-black">{{ $receipt['store_name'] }}</div>
            <div class="mt-0.5 text-[9px] font-bold">{{ $receipt['copy_type'] }}</div>
            <div class="text-[9px]">{{ $receipt['invoice_no'] }}</div>
            <div class="text-[9px]">{{ $receipt['date'] }} {{ $receipt['time'] }}</div>
        </div>
        <div class="my-1 border-y border-black py-1 text-[9px]"><div class="flex justify-between"><span>الكاشير</span><b>{{ $receipt['cashier'] }}</b></div></div>
        <table class="w-full border-collapse text-[9px]"><thead><tr class="border-b border-black"><th class="py-1 text-right">الصنف</th><th class="py-1 text-center">ك</th><th class="py-1 text-center">السعر</th><th class="py-1 text-left">الإجمالي</th></tr></thead><tbody>@foreach ($receipt['items'] as $item)<tr><td class="py-0.5 font-bold">{{ $item['name'] }}</td><td class="py-0.5 text-center">{{ $item['qty'] }}</td><td class="py-0.5 text-center">{{ $item['price'] }}</td><td class="py-0.5 text-left font-bold">{{ $item['total'] }}</td></tr>@endforeach</tbody></table>
        <div class="mt-1 border-t border-black pt-1 text-[9px]"><div class="flex justify-between"><span>المجموع</span><span>{{ $receipt['subtotal'] }}</span></div><div class="flex justify-between"><span>الخصم</span><span>{{ $receipt['discount'] }}</span></div><div class="flex justify-between border-t border-black pt-1 text-[12px] font-black"><span>الصافي</span><span>{{ $receipt['total_amount'] }}</span></div><div class="flex justify-between"><span>المدفوع</span><span>{{ $receipt['paid'] }}</span></div><div class="flex justify-between"><span>الباقي</span><span>{{ $receipt['change'] }}</span></div></div>
        <div class="mt-3 border-t border-dashed border-black pt-2 text-center text-[8px]">{{ $receipt['notice'] }}</div>
    @endif
</div>
<iframe id="silent-print-frame" style="display:none;width:0;height:0;border:0"></iframe>
