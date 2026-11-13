{{-- Expiry hint for a product: an expired batch, or one expiring within a week, is still in this branch's stock. --}}
@php($expiryWarning = $expiryWarnings[$productId] ?? null)
@if ($expiryWarning)
    <span data-pos-expiry="{{ $expiryWarning['status'] }}"
        title="{{ $expiryWarning['status'] === 'expired' ? 'لهذا الصنف دفعة انتهت صلاحيتها في '.$expiryWarning['date'].' — تأكد من القطعة قبل البيع.' : 'لهذا الصنف دفعة تنتهي صلاحيتها في '.$expiryWarning['date'].'.' }}"
        @class(['ms-1.5 inline-block rounded-md px-1.5 py-0.5 align-middle text-[9px] font-black',
            'bg-rose-600 text-white' => $expiryWarning['status'] === 'expired',
            'bg-amber-100 text-amber-800' => $expiryWarning['status'] !== 'expired'])>
        {{ $expiryWarning['status'] === 'expired' ? 'دفعة منتهية' : 'تنتهي '.\Illuminate\Support\Carbon::parse($expiryWarning['date'])->format('d/m') }}
    </span>
@endif
