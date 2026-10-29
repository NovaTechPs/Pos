<x-layouts::admin :title="'أخطاء النظام'">
    <flux:main>
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-5" dir="rtl">
            <div>
                <flux:heading size="xl">أخطاء النظام</flux:heading>
                <flux:text class="mt-2">لإدارة المنصة فقط. آخر ٥٠ خطأ من نهاية الملف المحدد، والأحدث أولًا.</flux:text>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                تُعرض أنواع الاستثناءات وأماكنها فقط. تُحجب نصوص الطلبات والاستعلامات والرسائل الحرة لحماية كلمات المرور وبيانات العملاء.
                الأوقات بتوقيت الخادم: {{ config('app.timezone') }}. تُسجّل الأخطاء الجديدة أيضًا في ملف diagnostics حتى لو كان السجل الرئيسي موجّهًا إلى مخرجات الاستضافة.
            </div>
            <form method="GET" action="{{ route('admin.system-errors') }}" class="flex flex-wrap items-end gap-3">
                <flux:field class="min-w-56 flex-1">
                    <flux:label>ملف السجل</flux:label>
                    <select name="file" class="w-full rounded-lg border border-zinc-300 bg-white p-2 text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white">
                        @forelse ($files as $file)
                            <option value="{{ $file }}" @selected($selectedFile === $file)>{{ $file }}</option>
                        @empty
                            <option value="">لا توجد ملفات متاحة</option>
                        @endforelse
                    </select>
                </flux:field>
                <flux:field>
                    <flux:label>المستوى</flux:label>
                    <select name="level" class="rounded-lg border border-zinc-300 bg-white p-2 text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white">
                        <option value="error" @selected($level === 'error')>أخطاء فقط</option>
                        <option value="warning" @selected($level === 'warning')>أخطاء وتحذيرات</option>
                    </select>
                </flux:field>
                <flux:button type="submit" variant="primary">تحديث السجل</flux:button>
            </form>
            <flux:text>آخر تحديث: {{ now()->format('Y-m-d H:i:s') }} · عدد النتائج: {{ count($entries) }}</flux:text>
            @forelse ($entries as $entry)
                <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" x-data="{ status: '' }">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <span dir="ltr" class="font-mono text-sm">{{ $entry['time'] }} · {{ $entry['level'] }}</span>
                        <div class="flex items-center gap-2">
                            <span x-text="status" role="status" class="text-sm"></span>
                            <flux:button type="button" size="sm" x-on:click="try { await navigator.clipboard.writeText($refs.details.value); status = 'تم النسخ'; } catch (e) { $refs.details.focus(); $refs.details.select(); status = 'اضغط Ctrl+C للنسخ'; }">نسخ التفاصيل</flux:button>
                        </div>
                    </div>
                    <textarea x-ref="details" readonly rows="6" dir="ltr" aria-label="تفاصيل الخطأ" class="w-full rounded-lg border border-zinc-200 bg-zinc-50 p-3 font-mono text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100">{{ $entry['time'].' '.$entry['level']."\n".$entry['details'] }}</textarea>
                </section>
            @empty
                <div role="status" class="rounded-xl border border-zinc-200 p-6 text-center text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
                    لا توجد أخطاء قابلة للعرض في آخر ٢٥٦ كيلوبايت من الملف. إذا لم توجد ملفات، تحقق من صلاحية كتابة مجلد storage/logs، ثم حدّث الصفحة بعد حدوث الخطأ.
                </div>
            @endforelse
        </div>
    </flux:main>
</x-layouts::admin>
