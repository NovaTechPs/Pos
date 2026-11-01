<x-layouts::tenant title="نسخ المتجر واستعادته">
    <main class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6" dir="rtl">
        <h1 class="text-2xl font-bold">نسخ المتجر واستعادته</h1>
        <p class="text-sm text-slate-600">نسخة مستقلة تشمل فروع المتجر والأصناف والأسعار والمخزون والزبائن والموردين والفواتير والذمم والموظفين والإعدادات والملفات المرتبطة.</p>
        @if (session('status')) <div role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-800">{{ session('status') }}</div> @endif
        @if ($errors->any()) <div role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div> @endif
        @if ($tenants->isEmpty())
            <p>لا توجد متاجر متاحة.</p>
        @else
            @if (auth()->user()->isSaaSAdmin())
                <form method="GET" action="{{ route('tenant.tenant-backups') }}" class="flex flex-wrap items-center gap-3">
                    <label for="backup-tenant">المتجر</label>
                    <select id="backup-tenant" name="tenant_id" class="rounded-lg border border-slate-300 p-2">
                        @foreach ($tenants as $item)<option value="{{ $item->id }}" @selected($tenant->id === $item->id)>{{ $item->name }}</option>@endforeach
                    </select>
                    <button class="rounded-lg bg-slate-800 px-4 py-2 text-white">عرض المتجر</button>
                </form>
            @endif
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="mb-3 text-lg font-bold">نسخة احتياطية — {{ $tenant->name }}</h2>
                <p class="mb-4 text-sm text-slate-600">الملف مشفّر ومخصص للاستعادة في هذا النظام ولن يحتوي بيانات المتاجر الأخرى. احتفظ بالملف خارج الاستضافة أيضاً، واحتفظ بمفتاح APP_KEY للخادم في مكان آمن؛ تغييره يمنع قراءة النسخ القديمة.</p>
                @can('backup.create')
                    <form method="POST" action="{{ route('tenant.tenant-backups.create', $tenant) }}">@csrf
                        <button class="rounded-xl bg-blue-600 px-5 py-3 font-bold text-white">إنشاء نسخة وتحميلها</button>
                    </form>
                @endcan
            </section>
            @can('backup.restore')
                <section class="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 text-lg font-bold">استعادة بيانات المتجر</h2>
                    <p class="mb-4 text-sm text-slate-600">اختر ملف ZIP صادراً من هذه الميزة لنفس المتجر. سنفحص التوقيع والجداول والعلاقات أولاً. لا يحدث أي تغيير عند الفحص. الحد الأقصى للرفع 100 MB ضمن حدود الاستضافة.</p>
                    <form method="POST" enctype="multipart/form-data" action="{{ route('tenant.tenant-backups.preview', $tenant) }}" class="flex flex-wrap items-center gap-3">@csrf
                        <input type="file" name="backup" accept=".zip" required aria-label="ملف النسخة الاحتياطية" class="max-w-full rounded-lg border p-2" />
                        <button class="rounded-xl bg-slate-800 px-5 py-3 font-bold text-white">فحص ومعاينة النسخة</button>
                    </form>
                    @if ($preview)
                        <div class="mt-5 space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4">
                            <h3 class="font-bold">معاينة النسخة: {{ $preview['manifest']['tenant_name'] }}</h3>
                            <p>تاريخ النسخة: <bdi>{{ $preview['manifest']['created_at'] }}</bdi></p>
                            <p>{{ count($preview['manifest']['tables']) }} جدول — {{ array_sum(array_column($preview['manifest']['tables'], 'count')) }} سجل — {{ count($preview['manifest']['files']) }} ملف</p>
                            <details><summary class="cursor-pointer">تفاصيل الجداول</summary><div class="mt-2 max-h-56 overflow-auto">@foreach ($preview['manifest']['tables'] as $name => $data)<p><bdi>{{ $name }}</bdi>: {{ $data['count'] }}</p>@endforeach</div></details>
                            <p class="font-bold text-red-800">الاستعادة تستبدل بيانات هذا المتجر بالنسخة، بما فيها حسابات الدخول. ستُحفظ نسخة أمان من البيانات الحالية أولاً. أوقف البيع على الأجهزة قبل الاستعادة، ثم أعد تسجيل الدخول والمزامنة؛ أجهزة ربط الفروع تحتاج إعادة تفعيل من المسؤول.</p>
                            <p class="text-sm">الاستعادة متاحة على PostgreSQL. لا تُستعاد الجلسات ومفاتيح الدخول القديمة، وتبقى خطة الاشتراك وحالة المتجر دون تغيير.</p>
                            <form method="POST" action="{{ route('tenant.tenant-backups.restore', $tenant) }}" class="space-y-3">@csrf
                                <input type="hidden" name="token" value="{{ $preview['token'] }}" />
                                <label class="block">للتأكيد اكتب اسم المتجر: <strong>{{ $tenant->name }}</strong><input name="confirmation" required autocomplete="off" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-2" /></label>
                                <label class="flex items-start gap-2"><input type="checkbox" name="acknowledge" value="1" required class="mt-1" /><span>أفهم أن البيانات الحالية لهذا المتجر ستُستبدل، وأن الأجهزة يجب إيقافها أثناء الاستعادة.</span></label>
                                <button class="rounded-xl bg-red-700 px-5 py-3 font-bold text-white">تأكيد الاستعادة لهذا المتجر</button>
                            </form>
                        </div>
                    @endif
                </section>
            @endcan
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="mb-3 text-lg font-bold">النسخ المحفوظة على الخادم</h2>
                <p class="mb-3 text-sm text-slate-600">هذه نسخ يدوية ونسخ أمان قبل الاستعادة. لا توجد جدولة تلقائية حالياً. التخزين المحلي للاستضافة قد يُفقد عند إعادة النشر؛ نزّل نسخة إلى جهازك.</p>
                <div class="overflow-x-auto"><table class="w-full text-right text-sm"><thead><tr><th class="p-2">التاريخ</th><th class="p-2">نوع النسخة</th><th class="p-2">السجلات</th><th class="p-2">الملف</th></tr></thead><tbody>
                    @forelse ($history as $item)
                        <tr class="border-t"><td class="p-2"><bdi>{{ $item['created_at'] }}</bdi></td><td class="p-2">{{ $item['kind'] === 'before-restore' ? 'أمان قبل الاستعادة' : 'نسخة يدوية' }}</td><td class="p-2">{{ array_sum(array_column($item['tables'], 'count')) }}</td><td class="p-2">@can('backup.create')<a class="font-bold text-blue-700" href="{{ route('tenant.tenant-backups.download', ['tenant' => $tenant, 'backup' => $item['id']]) }}">تحميل ZIP</a>@endcan</td></tr>
                    @empty <tr><td colspan="4" class="p-3 text-slate-500">لم تُنشأ نسخة لهذا المتجر بعد.</td></tr>@endforelse
                </tbody></table></div>
            </section>
        @endif
    </main>
</x-layouts::tenant>
