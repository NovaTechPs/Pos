@if (config('branch-sync.mode') === 'edge')
    <details data-pos-connection-status data-connection-state="{{ $connectionState }}" @if(config('app.desktop')) wire:poll.10s.visible="refreshLiveData" @endif class="relative" dir="rtl">
        <summary @class([
            'flex cursor-pointer items-center gap-1.5 rounded-xl border px-3 py-1.5 text-[10px] font-bold',
            'border-emerald-200 bg-emerald-50 text-emerald-700' => $connectionState === 'online',
            'border-slate-300 bg-slate-100 text-slate-700' => $connectionState === 'manual_offline',
            'border-amber-200 bg-amber-50 text-amber-900' => ! in_array($connectionState, ['online', 'manual_offline'], true),
        ])>
            <span aria-hidden="true">●</span>
            <span>{{ match($connectionState) {
                'online' => 'متصل بالسيرفر',
                'pending' => 'بانتظار المزامنة',
                'manual_offline' => 'دون اتصال (يدوي)',
                'update_required' => 'يلزم تحديث الاتصال',
                'unauthorized' => 'يلزم تسجيل الدخول',
                default => 'دون اتصال',
            } }}</span>
            @if ($pendingSyncOperations > 0)
                <span>({{ $pendingSyncOperations }})</span>
            @endif
            @if ($conflictedSyncOperations > 0)
                <span class="text-rose-700">⚠ {{ $conflictedSyncOperations }}</span>
            @endif
            <span aria-hidden="true">⌄</span>
        </summary>
        <div class="absolute end-0 top-full z-[70] mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-700 shadow-lg" role="status">
            <p>{{ match($connectionState) {
                'online' => 'بيانات مباشرة وحفظ فوري على السيرفر.',
                'pending' => 'توجد عمليات محلية بانتظار التأكيد أو المراجعة.',
                'manual_offline' => 'اخترت العمل دون اتصال. البيع مستمر من البيانات المحفوظة، وتُحفظ الفواتير محلياً حتى تعود للاتصال.',
                'update_required' => 'الوضع المحلي · يلزم تحديث الموقع لتفعيل البيانات المباشرة.',
                'unauthorized' => 'أعد تسجيل الدخول لتجديد الاتصال بالسيرفر.',
                'unavailable' => 'السيرفر غير متاح حاليًا؛ العمل على البيانات المحلية.',
                default => 'لا يوجد اتصال بالإنترنت؛ العمل على آخر بيانات محفوظة محليًا، وتُعاد المحاولة تلقائيًا.',
            } }}</p>
            <p class="mt-2">{{ $pendingSyncOperations }} بانتظار المزامنة</p>
            @if ($conflictedSyncOperations > 0)
                <p class="mt-2 font-bold text-rose-700">{{ $conflictedSyncOperations }} تعارض للمراجعة</p>
            @endif
            <div class="mt-3 flex flex-wrap gap-2">
                @if ($connectionState === 'manual_offline')
                    <button type="button" data-pos-go-online wire:click="setManualOffline(false)" wire:loading.attr="disabled" wire:target="setManualOffline" class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 font-bold text-emerald-900">العودة للاتصال والمزامنة</button>
                @else
                    <button type="button" wire:click="refreshLiveData" wire:loading.attr="disabled" wire:target="refreshLiveData" class="rounded-lg border border-sky-300 bg-sky-50 px-3 py-1.5 font-bold text-sky-900">مزامنة الآن</button>
                    @if (config('app.desktop'))
                        <button type="button" data-pos-go-offline wire:click="setManualOffline(true)" wire:loading.attr="disabled" wire:target="setManualOffline" class="rounded-lg border border-slate-300 bg-slate-50 px-3 py-1.5 font-bold text-slate-800">العمل دون اتصال</button>
                    @endif
                @endif
            </div>
        </div>
    </details>
@endif
