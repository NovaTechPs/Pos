<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use App\Models\Category;
use Illuminate\Validation\Rule;

new class extends Component {
    use WithPagination;

    public ?int $category_id = null;
    public string $name = '';
    public string $code = '';
    public string $description = '';
    public int $sort_order = 0;
    public bool $is_active = true;

    public string $search = '';
    public string $statusFilter = '';
    public bool $showModal = false;
    public bool $isEditing = false;

    protected function rules(): array
    {
        $tenantId = session('active_tenant_id');

        return [
            'name' => 'required|string|max:255',
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('categories', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($this->category_id),
            ],
            'description' => 'nullable|string|max:500',
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'boolean',
        ];
    }

    protected $validationAttributes = [
        'name' => 'اسم التصنيف',
        'code' => 'كود التصنيف',
        'description' => 'الوصف',
        'sort_order' => 'أولوية الترتيب',
        'is_active' => 'حالة التفعيل',
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[On('tenant-changed')]
    public function handleTenantChanged(): void
    {
        $this->resetPage();
        $this->closeModal();
    }

    public function openCreateModal(): void
    {
        $this->resetInputFields();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $tenantId = session('active_tenant_id');

        $category = Category::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $this->category_id = $category->id;
        $this->name = $category->name;
        $this->code = $category->code ?? '';
        $this->description = $category->description ?? '';
        $this->sort_order = (int) $category->sort_order;
        $this->is_active = (bool) $category->is_active;
        $this->isEditing = true;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate();

        $tenantId = session('active_tenant_id');

        if (!$tenantId) {
            session()->flash('message', 'يرجى اختيار المتجر أولاً لإتمام العملية.');
            return;
        }

        $data = [
            'tenant_id' => $tenantId,
            'name' => trim($this->name),
            'code' => trim($this->code) !== '' ? trim($this->code) : null,
            'description' => trim($this->description) !== '' ? trim($this->description) : null,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];

        if ($this->isEditing && $this->category_id) {
            Category::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($this->category_id)
                ->update($data);
        } else {
            Category::query()->create($data);
        }

        session()->flash(
            'message',
            $this->isEditing
                ? 'تم تحديث التصنيف بنجاح.'
                : 'تم إضافة التصنيف بنجاح.'
        );

        $this->closeModal();
    }

    public function delete(int $id): void
    {
        $tenantId = session('active_tenant_id');

        $category = Category::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        if ($category->products()->count() > 0) {
            session()->flash('message', 'لا يمكن حذف التصنيف لأنه يحتوي على منتجات مرتبطة به.');
            return;
        }

        $category->delete();

        session()->flash('message', 'تم نقل التصنيف إلى سلة المهملات.');
    }

    public function toggleStatus(int $id): void
    {
        $tenantId = session('active_tenant_id');

        $category = Category::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $category->update([
            'is_active' => !$category->is_active,
        ]);

        session()->flash(
            'message',
            $category->is_active
                ? 'تم تفعيل التصنيف.'
                : 'تم تعطيل التصنيف.'
        );
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetInputFields();
    }

    private function resetInputFields(): void
    {
        $this->category_id = null;
        $this->name = '';
        $this->code = '';
        $this->description = '';
        $this->sort_order = 0;
        $this->is_active = true;
        $this->resetValidation();
    }

    public function render()
    {
        $tenantId = session('active_tenant_id');

        $baseQuery = Category::query()
            ->where('tenant_id', $tenantId)
            ->when(trim($this->search) !== '', function ($query) {
                $search = trim($this->search);

                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('name', 'like', '%' . $search . '%')
                        ->orWhere('code', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%');
                });
            })
            ->when($this->statusFilter !== '', function ($query) {
                $query->where('is_active', $this->statusFilter === 'active');
            });

        $categories = (clone $baseQuery)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(12);

        $totalCount = (clone $baseQuery)->count();
        $activeCount = (clone $baseQuery)->where('is_active', true)->count();
        $inactiveCount = (clone $baseQuery)->where('is_active', false)->count();

        return $this->view([
            'categories' => $categories,
            'totalCount' => $totalCount,
            'activeCount' => $activeCount,
            'inactiveCount' => $inactiveCount,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main dir="rtl" class="min-h-[calc(100vh-4rem)] bg-slate-100 p-3 sm:p-4 lg:p-6">
    <div class="mx-auto max-w-[1500px] space-y-5">

        {{-- Header --}}
        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="bg-slate-950 px-4 py-5 text-white sm:px-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-start gap-3">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white/10 text-2xl">
                            🗂️
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-xl font-black sm:text-2xl">التصنيفات والأقسام</h1>
                            <p class="mt-1 max-w-2xl text-sm text-slate-300">
                                إدارة تصنيفات المنتجات وترتيب ظهورها في الكاشير والواجهات المرتبطة بالمتجر الحالي.
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        wire:click="openCreateModal"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl bg-emerald-500 px-5 py-3 text-sm font-black text-white transition hover:bg-emerald-400"
                    >
                        <span class="text-lg leading-none">+</span>
                        إضافة تصنيف
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-3 divide-x divide-x-reverse divide-slate-100 bg-white">
                <div class="px-4 py-4 text-center sm:text-right">
                    <div class="text-xs font-bold text-slate-400">إجمالي التصنيفات</div>
                    <div class="mt-1 text-xl font-black text-slate-900">{{ number_format($totalCount) }}</div>
                </div>
                <div class="px-4 py-4 text-center sm:text-right">
                    <div class="text-xs font-bold text-slate-400">المفعلة</div>
                    <div class="mt-1 text-xl font-black text-emerald-600">{{ number_format($activeCount) }}</div>
                </div>
                <div class="px-4 py-4 text-center sm:text-right">
                    <div class="text-xs font-bold text-slate-400">المعطلة</div>
                    <div class="mt-1 text-xl font-black text-slate-500">{{ number_format($inactiveCount) }}</div>
                </div>
            </div>
        </div>

        {{-- Message --}}
        @if (session()->has('message'))
            <div class="flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-100">✓</span>
                <span>{{ session('message') }}</span>
            </div>
        @endif

        {{-- Filters --}}
        <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="w-full lg:max-w-xl">
                    <label class="mb-2 block text-xs font-black text-slate-600">بحث التصنيفات</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">⌕</span>
                        <input
                            type="text"
                            wire:model.live.debounce.250ms="search"
                            placeholder="ابحث باسم التصنيف أو الكود أو الوصف..."
                            class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3 pl-4 pr-10 text-sm font-semibold outline-none transition placeholder:text-slate-400 focus:border-slate-400 focus:bg-white"
                        >
                    </div>
                </div>

                <div class="grid w-full grid-cols-3 gap-2 lg:max-w-md">
                    <button
                        type="button"
                        wire:click="$set('statusFilter', '')"
                        class="rounded-2xl border px-3 py-3 text-xs font-black transition {{ $statusFilter === '' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' }}"
                    >
                        الكل
                    </button>
                    <button
                        type="button"
                        wire:click="$set('statusFilter', 'active')"
                        class="rounded-2xl border px-3 py-3 text-xs font-black transition {{ $statusFilter === 'active' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' }}"
                    >
                        مفعل
                    </button>
                    <button
                        type="button"
                        wire:click="$set('statusFilter', 'inactive')"
                        class="rounded-2xl border px-3 py-3 text-xs font-black transition {{ $statusFilter === 'inactive' ? 'border-slate-700 bg-slate-700 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' }}"
                    >
                        معطل
                    </button>
                </div>
            </div>
        </div>

        {{-- Categories --}}
        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-4 sm:px-6">
                <div>
                    <h2 class="text-base font-black text-slate-900">قائمة التصنيفات</h2>
                    <p class="mt-1 text-xs font-semibold text-slate-400">مرتبة حسب أولوية العرض.</p>
                </div>
                <div class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-black text-slate-600">
                    {{ $categories->total() }} نتيجة
                </div>
            </div>

            {{-- Desktop --}}
            <div class="hidden overflow-x-auto lg:block">
                <table class="w-full min-w-[900px] text-right">
                    <thead class="border-b border-slate-100 bg-slate-50">
                        <tr class="text-[11px] font-black text-slate-500">
                            <th class="px-5 py-4">الترتيب</th>
                            <th class="px-5 py-4">التصنيف</th>
                            <th class="px-5 py-4">الكود</th>
                            <th class="px-5 py-4">الوصف</th>
                            <th class="px-5 py-4">الحالة</th>
                            <th class="px-5 py-4 text-left">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($categories as $category)
                            <tr wire:key="category-row-{{ $category->id }}" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="inline-flex h-9 min-w-9 items-center justify-center rounded-xl bg-slate-100 px-2 font-mono text-sm font-black text-slate-700">
                                        {{ $category->sort_order }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 font-black text-indigo-700">
                                            {{ mb_substr($category->name, 0, 1) }}
                                        </div>
                                        <div>
                                            <div class="text-sm font-black text-slate-900">{{ $category->name }}</div>
                                            <div class="mt-1 text-[11px] text-slate-400">#{{ $category->id }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    @if ($category->code)
                                        <span class="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 font-mono text-xs font-black text-slate-700">{{ $category->code }}</span>
                                    @else
                                        <span class="text-xs font-semibold text-slate-400">بدون كود</span>
                                    @endif
                                </td>
                                <td class="max-w-[320px] px-5 py-4">
                                    <div class="truncate text-sm font-semibold text-slate-600">
                                        {{ $category->description ?: 'لا يوجد وصف' }}
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    @if ($category->is_active)
                                        <button type="button" wire:click="toggleStatus({{ $category->id }})" class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-[11px] font-black text-emerald-700 transition hover:bg-emerald-100">مفعل</button>
                                    @else
                                        <button type="button" wire:click="toggleStatus({{ $category->id }})" class="rounded-full border border-slate-200 bg-slate-100 px-3 py-1.5 text-[11px] font-black text-slate-600 transition hover:bg-slate-200">معطل</button>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" wire:click="edit({{ $category->id }})" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700 transition hover:bg-slate-50">تعديل</button>
                                        <button type="button" wire:click="delete({{ $category->id }})" wire:confirm="هل أنت متأكد من نقل هذا التصنيف إلى سلة المهملات؟" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-black text-rose-700 transition hover:bg-rose-100">حذف</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-16 text-center">
                                    <div class="mx-auto max-w-sm">
                                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 text-2xl">🗂️</div>
                                        <h3 class="mt-4 text-base font-black text-slate-800">لا توجد تصنيفات</h3>
                                        <p class="mt-1 text-sm font-semibold text-slate-500">أضف أول تصنيف للمتجر أو غيّر البحث الحالي.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile --}}
            <div class="divide-y divide-slate-100 lg:hidden">
                @forelse ($categories as $category)
                    <div wire:key="mobile-category-{{ $category->id }}" class="p-4">
                        <div class="flex items-start gap-3">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-50 font-black text-indigo-700">
                                {{ mb_substr($category->name, 0, 1) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-black text-slate-900">{{ $category->name }}</div>
                                        <div class="mt-1 text-xs font-semibold text-slate-400">الترتيب: {{ $category->sort_order }}</div>
                                    </div>
                                    @if ($category->is_active)
                                        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-black text-emerald-700">مفعل</span>
                                    @else
                                        <span class="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 text-[10px] font-black text-slate-600">معطل</span>
                                    @endif
                                </div>

                                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                    <div class="rounded-xl bg-slate-50 p-2.5">
                                        <div class="text-slate-400">الكود</div>
                                        <div class="mt-1 font-mono font-black text-slate-700">{{ $category->code ?: '—' }}</div>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-2.5">
                                        <div class="text-slate-400">الوصف</div>
                                        <div class="mt-1 truncate font-semibold text-slate-700">{{ $category->description ?: '—' }}</div>
                                    </div>
                                </div>

                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    <button type="button" wire:click="edit({{ $category->id }})" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-black text-slate-700">تعديل</button>
                                    <button type="button" wire:click="delete({{ $category->id }})" wire:confirm="هل أنت متأكد من نقل هذا التصنيف إلى سلة المهملات؟" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-xs font-black text-rose-700">حذف</button>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="px-6 py-16 text-center text-sm font-semibold text-slate-400">لا توجد نتائج.</div>
                @endforelse
            </div>

            @if ($categories->hasPages())
                <div class="border-t border-slate-100 px-4 py-4 sm:px-6">
                    {{ $categories->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- Modal --}}
    @if ($showModal)
        <div
            x-data
            x-show="true"
            x-on:keydown.escape.window="$wire.closeModal()"
            class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/50 p-0 backdrop-blur-sm sm:items-center sm:p-4"
        >
            <div wire:click.stop class="max-h-[92vh] w-full overflow-y-auto rounded-t-3xl bg-white shadow-2xl sm:max-w-2xl sm:rounded-3xl">
                <div class="sticky top-0 z-10 border-b border-slate-100 bg-white/95 px-5 py-4 backdrop-blur sm:px-6">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-black text-slate-900">{{ $isEditing ? 'تعديل التصنيف' : 'إضافة تصنيف جديد' }}</h2>
                            <p class="mt-1 text-xs font-semibold text-slate-400">أدخل بيانات التصنيف ثم احفظ التغييرات.</p>
                        </div>
                        <button type="button" wire:click="closeModal" class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-500 transition hover:bg-slate-200">✕</button>
                    </div>
                </div>

                <form wire:submit="save" class="space-y-5 p-5 sm:p-6">
                    <div>
                        <label class="mb-2 block text-xs font-black text-slate-700">اسم التصنيف</label>
                        <input type="text" wire:model="name" autofocus placeholder="مثال: مشروبات، حلويات، عناية..." class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold outline-none transition focus:border-slate-400 focus:bg-white">
                        @error('name') <div class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</div> @enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-xs font-black text-slate-700">كود التصنيف <span class="font-semibold text-slate-400">(اختياري)</span></label>
                            <input type="text" wire:model="code" placeholder="CAT-01" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-sm font-semibold outline-none transition focus:border-slate-400 focus:bg-white">
                            @error('code') <div class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-2 block text-xs font-black text-slate-700">أولوية الترتيب</label>
                            <input type="number" min="0" wire:model="sort_order" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-sm font-semibold outline-none transition focus:border-slate-400 focus:bg-white">
                            @error('sort_order') <div class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-xs font-black text-slate-700">الوصف <span class="font-semibold text-slate-400">(اختياري)</span></label>
                        <textarea wire:model="description" rows="4" placeholder="وصف مختصر يساعد في تنظيم التصنيفات..." class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold outline-none transition focus:border-slate-400 focus:bg-white"></textarea>
                        @error('description') <div class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</div> @enderror
                    </div>

                    <label class="flex cursor-pointer items-center justify-between rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div>
                            <div class="text-sm font-black text-slate-800">إظهار التصنيف</div>
                            <div class="mt-1 text-xs font-semibold text-slate-400">السماح باستخدامه في واجهة الكاشير.</div>
                        </div>
                        <input type="checkbox" wire:model="is_active" class="h-5 w-5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                    </label>

                    <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
                        <button type="button" wire:click="closeModal" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-black text-slate-700 transition hover:bg-slate-50">إلغاء</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-black text-white transition hover:bg-slate-800 disabled:opacity-50">
                            <span wire:loading.remove wire:target="save">{{ $isEditing ? 'حفظ التعديل' : 'إنشاء التصنيف' }}</span>
                            <span wire:loading wire:target="save">جاري الحفظ...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</flux:main>
