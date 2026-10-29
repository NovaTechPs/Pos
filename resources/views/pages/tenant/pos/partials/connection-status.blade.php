@if (config('branch-sync.mode') === 'edge')
    <details data-pos-connection-status @if(config('app.desktop')) wire:poll.10s.visible="refreshLiveData" @endif class="relative" dir="rtl">
        <summary @class([
            'flex cursor-pointer items-center gap-1.5 rounded-xl border px-3 py-1.5 text-[10px] font-bold',
            'border-emerald-200 bg-emerald-50 text-emerald-700' => $connectionState === 'online',
            'border-amber-200 bg-amber-50 text-amber-900' => $connectionState !== 'online',
        ])>
            <span aria-hidden="true">●</span>
            <span>{{ match($connectionState) {
                'online' => 'متصل بالسيرفر',
                'pending' => 'بانتظار المزامنة',
                'update_required' => 'يلزم تحديث الاتصال',
                'unauthorized' => 'يلزم تسجيل الدخول',
                default => 'دون اتصال',
            } }}</span>
            @if ($pendingSyncOperations > 0)
                <span>({{ $pendingSyncOperations }})</span>
            @endif
            <span aria-hidden="true">⌄</span>
        </summary>
        <div class="absolute end-0 top-full z-[70] mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-700 shadow-lg" role="status">
            <p>{{ match($connectionState) {
                'online' => 'بيانات مباشرة وحفظ فوري على السيرفر.',
                'pending' => 'توجد عمليات محلية بانتظار التأكيد أو المراجعة.',
                'update_required' => 'الوضع المحلي · يلزم تحديث الموقع لتفعيل البيانات المباشرة.',
                'unauthorized' => 'أعد تسجيل الدخول لتجديد الاتصال بالسيرفر.',
                'unavailable' => 'السيرفر غير متاح حاليًا؛ العمل على البيانات المحلية.',
                default => 'العمل على آخر بيانات محفوظة محليًا.',
            } }}</p>
            <p class="mt-2">{{ $pendingSyncOperations }} بانتظار المزامنة</p>
            @if ($conflictedSyncOperations > 0)
                <p class="mt-2 font-bold text-rose-700">{{ $conflictedSyncOperations }} تعارض للمراجعة</p>
            @endif
            <button type="button" wire:click="refreshLiveData" wire:loading.attr="disabled" wire:target="refreshLiveData" class="mt-3 rounded-lg border border-sky-300 bg-sky-50 px-3 py-1.5 font-bold text-sky-900">تحديث الحالة</button>
        </div>
    </details>
@endif
