<?php

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\Branch;
use Illuminate\Validation\Rule;

new class extends Component
{
    /*
    |--------------------------------------------------------------------------
    | Data
    |--------------------------------------------------------------------------
    */

    public $branches = [];

    /*
    |--------------------------------------------------------------------------
    | Form
    |--------------------------------------------------------------------------
    */

    public $branch_id = null;

    public string $name = '';

    /**
     * امتداد صفحة المشروع
     * مثال:
     * nablus
     * ramallah
     * main-store
     */
    public string $domain = '';

    public string $phone = '';

    public string $address = '';

    public string $type = 'branch';

    /*
    |--------------------------------------------------------------------------
    | Modal
    |--------------------------------------------------------------------------
    */

    public bool $showModal = false;

    public bool $isEditing = false;

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public string $search = '';

    public string $typeFilter = 'all';

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    */

    public function mount(): void
    {
        $this->loadBranches();
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'domain' => [
                'required',
                'string',
                'max:100',

                /*
                 * يسمح فقط بـ:
                 * a-z
                 * A-Z
                 * 0-9
                 * -
                 *
                 * ويمنع:
                 * المسافات
                 * /
                 * .
                 * https://
                 * الرموز الخاصة
                 */
                'regex:/^[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*$/',

                Rule::unique('branches', 'domain')
                    ->where(function ($query) {
                        return $query->where(
                            'tenant_id',
                            $this->getTenantId()
                        );
                    })
                    ->ignore($this->branch_id),
            ],

            'phone' => [
                'nullable',
                'string',
                'max:20',
            ],

            'address' => [
                'nullable',
                'string',
                'max:500',
            ],

            'type' => [
                'required',
                'in:branch,warehouse',
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' =>
                'اسم الموقع مطلوب.',

            'domain.required' =>
                'امتداد المشروع مطلوب.',

            'domain.regex' =>
                'امتداد المشروع يجب أن يحتوي على أحرف وأرقام وشرطة (-) فقط. مثال: nablus أو main-store.',

            'domain.unique' =>
                'هذا الامتداد مستخدم بالفعل في موقع آخر داخل هذا المتجر.',

            'domain.max' =>
                'امتداد المشروع طويل جدًا.',

            'phone.max' =>
                'رقم الهاتف طويل جدًا.',

            'address.max' =>
                'العنوان طويل جدًا.',

            'type.required' =>
                'يرجى اختيار نوع الموقع.',

            'type.in' =>
                'نوع الموقع غير صالح.',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    */

    private function getTenantId(): ?int
    {
        $tenantId = session('active_tenant_id');

        return $tenantId
            ? (int) $tenantId
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Domain / Slug
    |--------------------------------------------------------------------------
    */

    private function normalizeDomain(?string $domain): string
    {
        $domain = trim((string) $domain);

        /*
         * تحويل الأحرف إلى lowercase
         */
        $domain = strtolower($domain);

        /*
         * إزالة المسافات
         */
        $domain = preg_replace('/\s+/', '', $domain);

        /*
         * إزالة أي شيء غير:
         * a-z
         * 0-9
         * -
         */
        $domain = preg_replace(
            '/[^a-z0-9-]/',
            '',
            $domain
        );

        /*
         * منع أكثر من - متتالية
         */
        $domain = preg_replace(
            '/-+/',
            '-',
            $domain
        );

        /*
         * إزالة - من البداية والنهاية
         */
        $domain = trim(
            $domain,
            '-'
        );

        return $domain;
    }

    /*
    |--------------------------------------------------------------------------
    | Load Branches
    |--------------------------------------------------------------------------
    */

    #[On('tenant-changed')]
    public function loadBranches(): void
    {
        $this->resetValidation();

        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            $this->branches = collect();

            return;
        }

        $this->branches = Branch::query()
            ->where('tenant_id', $tenantId)

            ->when(
                filled(trim($this->search)),
                function ($query) {
                    $search = trim($this->search);

                    $query->where(function ($query) use ($search) {
                        $query
                            ->where(
                                'name',
                                'like',
                                "%{$search}%"
                            )

                            ->orWhere(
                                'domain',
                                'like',
                                "%{$search}%"
                            )

                            ->orWhere(
                                'phone',
                                'like',
                                "%{$search}%"
                            )

                            ->orWhere(
                                'address',
                                'like',
                                "%{$search}%"
                            );
                    });
                }
            )

            ->when(
                $this->typeFilter !== 'all',
                fn ($query) =>
                    $query->where(
                        'type',
                        $this->typeFilter
                    )
            )

            ->orderBy('type')
            ->orderBy('name')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public function updatedSearch(): void
    {
        $this->loadBranches();
    }

    public function updatedTypeFilter(): void
    {
        $this->loadBranches();
    }

    public function clearFilters(): void
    {
        $this->search = '';

        $this->typeFilter = 'all';

        $this->loadBranches();
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function openCreateModal(): void
    {
        $this->resetInputFields();

        $this->isEditing = false;

        $this->showModal = true;
    }

    /*
    |--------------------------------------------------------------------------
    | Edit
    |--------------------------------------------------------------------------
    */

    public function edit(int $id): void
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            $this->flashError(
                'يرجى اختيار المتجر أولاً.'
            );

            return;
        }

        $branch = Branch::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $this->branch_id = $branch->id;

        $this->name = $branch->name;

        $this->domain = $branch->domain ?? '';

        $this->phone = $branch->phone ?? '';

        $this->address = $branch->address ?? '';

        $this->type = $branch->type;

        $this->resetValidation();

        $this->isEditing = true;

        $this->showModal = true;
    }

    /*
    |--------------------------------------------------------------------------
    | Save
    |--------------------------------------------------------------------------
    */

    public function save(): void
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            $this->flashError(
                'يرجى اختيار متجر أولاً لتتمكن من إدارة الفروع والمخازن.'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize domain before validation
        |--------------------------------------------------------------------------
        */

        $this->domain = $this->normalizeDomain(
            $this->domain
        );

        /*
        |--------------------------------------------------------------------------
        | Validate
        |--------------------------------------------------------------------------
        */

        $validated = $this->validate();

        /*
        |--------------------------------------------------------------------------
        | Update
        |--------------------------------------------------------------------------
        */

        if ($this->isEditing) {

            $branch = Branch::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($this->branch_id);

            $branch->update([
                'name' => $validated['name'],

                'domain' => $validated['domain'],

                'phone' => $validated['phone'] ?? null,

                'address' => $validated['address'] ?? null,

                'type' => $validated['type'],
            ]);

            $message =
                'تم تحديث بيانات الموقع بنجاح.';
        }

        /*
        |--------------------------------------------------------------------------
        | Create
        |--------------------------------------------------------------------------
        */

        else {

            Branch::create([
                'tenant_id' => $tenantId,

                'name' => $validated['name'],

                'domain' => $validated['domain'],

                'phone' => $validated['phone'] ?? null,

                'address' => $validated['address'] ?? null,

                'type' => $validated['type'],
            ]);

            $message =
                'تم إضافة الموقع بنجاح.';
        }

        /*
        |--------------------------------------------------------------------------
        | Close
        |--------------------------------------------------------------------------
        */

        $this->closeModal();

        $this->loadBranches();

        session()->flash(
            'message',
            $message
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function delete(int $id): void
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            $this->flashError(
                'يرجى اختيار المتجر أولاً.'
            );

            return;
        }

        $branch = Branch::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $branch->delete();

        $this->loadBranches();

        session()->flash(
            'message',
            'تم حذف الموقع بنجاح.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Modal
    |--------------------------------------------------------------------------
    */

    public function closeModal(): void
    {
        $this->showModal = false;

        $this->resetInputFields();
    }

    /*
    |--------------------------------------------------------------------------
    | Reset Form
    |--------------------------------------------------------------------------
    */

    private function resetInputFields(): void
    {
        $this->branch_id = null;

        $this->name = '';

        $this->domain = '';

        $this->phone = '';

        $this->address = '';

        $this->type = 'branch';

        $this->resetValidation();
    }

    /*
    |--------------------------------------------------------------------------
    | Flash Error
    |--------------------------------------------------------------------------
    */

    private function flashError(string $message): void
    {
        session()->flash(
            'error',
            $message
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        return $this->view()
            ->layout('layouts::tenant');
    }
};

?>

<flux:main class="space-y-6">

    {{-- ============================================================
        HEADER
    ============================================================= --}}

    <div class="space-y-5">

        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">

            <div class="min-w-0">

                <div class="flex items-center gap-3">

                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900"
                    >
                        <flux:icon.building-storefront class="size-5" />
                    </div>

                    <div class="min-w-0">

                        <flux:heading
                            size="xl"
                            level="1"
                            class="truncate"
                        >
                            الفروع والمخازن
                        </flux:heading>

                        <flux:subheading class="mt-1">
                            إدارة مواقع البيع والتخزين التابعة للمتجر الحالي.
                        </flux:subheading>

                    </div>

                </div>

            </div>

            <flux:button
                variant="primary"
                icon="plus"
                wire:click="openCreateModal"
                wire:loading.attr="disabled"
                wire:target="openCreateModal"
                class="shrink-0"
            >
                إضافة موقع جديد
            </flux:button>

        </div>


        {{-- Success --}}

        @if (session()->has('message'))

            <div
                class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300"
            >

                <div class="flex items-center gap-2">

                    <flux:icon.check-circle class="size-5 shrink-0" />

                    <span>
                        {{ session('message') }}
                    </span>

                </div>

            </div>

        @endif


        {{-- Error --}}

        @if (session()->has('error'))

            <div
                class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300"
            >

                <div class="flex items-center gap-2">

                    <flux:icon.exclamation-triangle class="size-5 shrink-0" />

                    <span>
                        {{ session('error') }}
                    </span>

                </div>

            </div>

        @endif

    </div>


    {{-- ============================================================
        STATISTICS
    ============================================================= --}}

    @php

        $totalLocations = $branches->count();

        $totalBranches = $branches
            ->where('type', 'branch')
            ->count();

        $totalWarehouses = $branches
            ->where('type', 'warehouse')
            ->count();

    @endphp


    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">

        <div
            class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
        >

            <div class="flex items-center justify-between">

                <div>

                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">
                        إجمالي المواقع
                    </p>

                    <p class="mt-2 text-2xl font-semibold text-zinc-900 dark:text-white">
                        {{ $totalLocations }}
                    </p>

                </div>

                <div
                    class="flex h-11 w-11 items-center justify-center rounded-xl bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                >
                    <flux:icon.building-office-2 class="size-5" />
                </div>

            </div>

        </div>


        <div
            class="rounded-2xl border border-emerald-200/70 bg-emerald-50/60 p-5 shadow-sm dark:border-emerald-900/50 dark:bg-emerald-950/20"
        >

            <div class="flex items-center justify-between">

                <div>

                    <p class="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                        فروع البيع
                    </p>

                    <p class="mt-2 text-2xl font-semibold text-emerald-800 dark:text-emerald-300">
                        {{ $totalBranches }}
                    </p>

                </div>

                <div
                    class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"
                >
                    <flux:icon.building-storefront class="size-5" />
                </div>

            </div>

        </div>


        <div
            class="rounded-2xl border border-indigo-200/70 bg-indigo-50/60 p-5 shadow-sm dark:border-indigo-900/50 dark:bg-indigo-950/20"
        >

            <div class="flex items-center justify-between">

                <div>

                    <p class="text-sm font-medium text-indigo-700 dark:text-indigo-400">
                        المخازن
                    </p>

                    <p class="mt-2 text-2xl font-semibold text-indigo-800 dark:text-indigo-300">
                        {{ $totalWarehouses }}
                    </p>

                </div>

                <div
                    class="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300"
                >
                    <flux:icon.archive-box class="size-5" />
                </div>

            </div>

        </div>

    </div>


    {{-- ============================================================
        FILTERS
    ============================================================= --}}

    <div
        class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
    >

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">

            <div class="min-w-0 flex-1">

                <flux:input
                    wire:model.live.debounce.350ms="search"
                    icon="magnifying-glass"
                    placeholder="ابحث باسم الموقع أو الامتداد أو الهاتف أو العنوان..."
                />

            </div>


            <div class="w-full lg:w-48">

                <flux:select wire:model.live="typeFilter">

                    <option value="all">
                        كل المواقع
                    </option>

                    <option value="branch">
                        فروع البيع
                    </option>

                    <option value="warehouse">
                        المخازن
                    </option>

                </flux:select>

            </div>


            @if (
                filled($search) ||
                $typeFilter !== 'all'
            )

                <flux:button
                    variant="ghost"
                    icon="x-mark"
                    wire:click="clearFilters"
                    wire:loading.attr="disabled"
                    wire:target="clearFilters"
                    class="shrink-0"
                >
                    مسح
                </flux:button>

            @endif

        </div>


        <div class="mt-3 flex items-center justify-between gap-3 text-xs text-zinc-500">

            <span>
                عرض {{ $branches->count() }} موقع
            </span>

            <span
                wire:loading
                wire:target="search,typeFilter,clearFilters"
                class="inline-flex items-center gap-2"
            >

                <flux:icon.arrow-path class="size-3.5 animate-spin" />

                جاري تحديث النتائج...

            </span>

        </div>

    </div>


    {{-- ============================================================
        TABLE
    ============================================================= --}}

    <div
        class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
    >

        <flux:table>

            <flux:table.columns>

                <flux:table.column>
                    الموقع
                </flux:table.column>

                <flux:table.column>
                    النوع
                </flux:table.column>

                <flux:table.column>
                    امتداد المشروع
                </flux:table.column>

                <flux:table.column>
                    الهاتف
                </flux:table.column>

                <flux:table.column>
                    العنوان
                </flux:table.column>

                <flux:table.column align="end">
                    الإجراءات
                </flux:table.column>

            </flux:table.columns>


            <flux:table.rows>

                @forelse ($branches as $branch)

                    <flux:table.row
                        wire:key="branch-{{ $branch->id }}"
                    >

                        {{-- =================================================
                            Name
                        ================================================== --}}

                        <flux:table.cell>

                            <div class="flex items-center gap-3">

                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl
                                    {{
                                        $branch->type === 'branch'
                                            ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400'
                                            : 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-400'
                                    }}"
                                >

                                    @if ($branch->type === 'branch')

                                        <flux:icon.building-storefront class="size-5" />

                                    @else

                                        <flux:icon.archive-box class="size-5" />

                                    @endif

                                </div>


                                <div class="min-w-0">

                                    <div class="font-medium text-zinc-900 dark:text-white">
                                        {{ $branch->name }}
                                    </div>

                                    <div class="mt-0.5 text-xs text-zinc-400">
                                        #{{ $branch->id }}
                                    </div>

                                </div>

                            </div>

                        </flux:table.cell>


                        {{-- =================================================
                            Type
                        ================================================== --}}

                        <flux:table.cell>

                            @if ($branch->type === 'branch')

                                <flux:badge
                                    size="sm"
                                    color="emerald"
                                    variant="solid"
                                >
                                    فرع بيع
                                </flux:badge>

                            @else

                                <flux:badge
                                    size="sm"
                                    color="indigo"
                                    variant="solid"
                                >
                                    مخزن
                                </flux:badge>

                            @endif

                        </flux:table.cell>


                        {{-- =================================================
                            Domain / Project Extension
                        ================================================== --}}

                        <flux:table.cell>

                            @if (filled($branch->domain))

                                <div class="flex items-center gap-2">

                                    <div
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                                    >

                                        <flux:icon.link class="size-4" />

                                    </div>


                                    <div class="min-w-0">

                                        <div
                                            dir="ltr"
                                            class="max-w-[220px] truncate text-sm font-bold text-zinc-700 dark:text-zinc-200"
                                            title="/{{ $branch->domain }}"
                                        >
                                            /{{ $branch->domain }}
                                        </div>

                                        <div class="mt-0.5 text-xs text-zinc-400">
                                            امتداد صفحة المشروع
                                        </div>

                                    </div>

                                </div>

                            @else

                                <span class="text-xs text-zinc-400">
                                    غير محدد
                                </span>

                            @endif

                        </flux:table.cell>


                        {{-- =================================================
                            Phone
                        ================================================== --}}

                        <flux:table.cell>

                            @if (filled($branch->phone))

                                <span
                                    dir="ltr"
                                    class="text-sm text-zinc-600 dark:text-zinc-300"
                                >
                                    {{ $branch->phone }}
                                </span>

                            @else

                                <span class="text-xs text-zinc-400">
                                    غير محدد
                                </span>

                            @endif

                        </flux:table.cell>


                        {{-- =================================================
                            Address
                        ================================================== --}}

                        <flux:table.cell>

                            @if (filled($branch->address))

                                <span class="text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ $branch->address }}
                                </span>

                            @else

                                <span class="text-xs text-zinc-400">
                                    غير محدد
                                </span>

                            @endif

                        </flux:table.cell>


                        {{-- =================================================
                            Actions
                        ================================================== --}}

                        <flux:table.cell align="end">

                            <div class="flex items-center justify-end gap-1">

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="pencil-square"
                                    wire:click="edit({{ $branch->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="edit({{ $branch->id }})"
                                    title="تعديل الموقع"
                                />

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30"
                                    wire:click="delete({{ $branch->id }})"
                                    wire:confirm="هل أنت متأكد من حذف هذا الموقع؟"
                                    wire:loading.attr="disabled"
                                    wire:target="delete({{ $branch->id }})"
                                    title="حذف الموقع"
                                />

                            </div>

                        </flux:table.cell>

                    </flux:table.row>

                @empty

                    <flux:table.row>

                        <flux:table.cell
                            colspan="6"
                            align="center"
                            class="py-16"
                        >

                            <div class="mx-auto flex max-w-md flex-col items-center">

                                <div
                                    class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400 dark:bg-zinc-800"
                                >
                                    <flux:icon.building-storefront class="size-8" />
                                </div>


                                @if (
                                    filled($search) ||
                                    $typeFilter !== 'all'
                                )

                                    <flux:heading size="lg">
                                        لا توجد نتائج
                                    </flux:heading>

                                    <p class="mt-2 text-sm text-zinc-500">
                                        لم نجد مواقع تطابق معايير البحث الحالية.
                                    </p>

                                    <flux:button
                                        variant="ghost"
                                        class="mt-4"
                                        wire:click="clearFilters"
                                    >
                                        مسح الفلاتر
                                    </flux:button>

                                @else

                                    <flux:heading size="lg">
                                        لا توجد فروع أو مخازن
                                    </flux:heading>

                                    <p class="mt-2 text-sm text-zinc-500">
                                        ابدأ بإضافة أول فرع بيع أو مخزن إلى المتجر.
                                    </p>

                                    <flux:button
                                        variant="primary"
                                        icon="plus"
                                        class="mt-4"
                                        wire:click="openCreateModal"
                                    >
                                        إضافة أول موقع
                                    </flux:button>

                                @endif

                            </div>

                        </flux:table.cell>

                    </flux:table.row>

                @endforelse

            </flux:table.rows>

        </flux:table>

    </div>


    {{-- ============================================================
        CREATE / EDIT MODAL
    ============================================================= --}}

    <flux:modal
        wire:model="showModal"
        class="md:w-[620px]"
    >

        <form
            wire:submit="save"
            class="space-y-6"
        >

            {{-- Header --}}

            <div>

                <div class="flex items-start gap-3">

                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl
                        {{
                            $isEditing
                                ? 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-400'
                                : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                        }}"
                    >

                        @if ($isEditing)

                            <flux:icon.pencil-square class="size-5" />

                        @else

                            <flux:icon.plus class="size-5" />

                        @endif

                    </div>


                    <div>

                        <flux:heading size="lg">

                            {{
                                $isEditing
                                    ? 'تعديل بيانات الموقع'
                                    : 'إضافة موقع جديد'
                            }}

                        </flux:heading>

                        <flux:subheading class="mt-1">

                            {{
                                $isEditing
                                    ? 'حدّث بيانات الفرع أو المخزن ومعلومات صفحة المشروع.'
                                    : 'أدخل بيانات الفرع أو المخزن الجديد.'
                            }}

                        </flux:subheading>

                    </div>

                </div>

            </div>


            {{-- ========================================================
                TYPE
            ========================================================= --}}

            <div class="space-y-4">

                <div class="text-xs font-semibold uppercase tracking-wider text-zinc-400">
                    نوع الموقع
                </div>


                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">

                    {{-- Branch --}}

                    <button
                        type="button"
                        wire:click="$set('type', 'branch')"
                        class="rounded-xl border p-4 text-right transition
                        {{
                            $type === 'branch'
                                ? 'border-emerald-300 bg-emerald-50 ring-2 ring-emerald-100 dark:border-emerald-700 dark:bg-emerald-950/30 dark:ring-emerald-950'
                                : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900'
                        }}"
                    >

                        <div class="flex items-center gap-3">

                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl
                                {{
                                    $type === 'branch'
                                        ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400'
                                        : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800'
                                }}"
                            >
                                <flux:icon.building-storefront class="size-5" />
                            </div>

                            <div>

                                <div class="text-sm font-medium text-zinc-900 dark:text-white">
                                    فرع بيع
                                </div>

                                <div class="mt-0.5 text-xs text-zinc-500">
                                    نقطة بيع وتشغيل POS
                                </div>

                            </div>

                        </div>

                    </button>


                    {{-- Warehouse --}}

                    <button
                        type="button"
                        wire:click="$set('type', 'warehouse')"
                        class="rounded-xl border p-4 text-right transition
                        {{
                            $type === 'warehouse'
                                ? 'border-indigo-300 bg-indigo-50 ring-2 ring-indigo-100 dark:border-indigo-700 dark:bg-indigo-950/30 dark:ring-indigo-950'
                                : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900'
                        }}"
                    >

                        <div class="flex items-center gap-3">

                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl
                                {{
                                    $type === 'warehouse'
                                        ? 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-400'
                                        : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800'
                                }}"
                            >
                                <flux:icon.archive-box class="size-5" />
                            </div>

                            <div>

                                <div class="text-sm font-medium text-zinc-900 dark:text-white">
                                    مخزن
                                </div>

                                <div class="mt-0.5 text-xs text-zinc-500">
                                    موقع لتخزين المنتجات والمخزون
                                </div>

                            </div>

                        </div>

                    </button>

                </div>


                @error('type')

                    <div class="text-sm text-red-600 dark:text-red-400">
                        {{ $message }}
                    </div>

                @enderror

            </div>


            {{-- ========================================================
                BASIC INFORMATION
            ========================================================= --}}

            <div class="space-y-4">

                <div class="text-xs font-semibold uppercase tracking-wider text-zinc-400">
                    البيانات الأساسية
                </div>


                {{-- Name --}}

                <flux:field>

                    <flux:label>
                        اسم الموقع
                    </flux:label>

                    <flux:input
                        wire:model="name"
                        placeholder="مثال: فرع نابلس"
                        autocomplete="organization"
                    />

                    <flux:error name="name" />

                </flux:field>


                {{-- Domain --}}

                <flux:field>

                    <flux:label>
                        امتداد صفحة المشروع
                    </flux:label>

                    <flux:input
                        wire:model="domain"
                        placeholder="nablus"
                        dir="ltr"
                        autocomplete="off"
                    />

                    <flux:description>
                        مثال:
                        <span dir="ltr" class="font-semibold">
                            nablus
                        </span>

                        أو

                        <span dir="ltr" class="font-semibold">
                            main-store
                        </span>

                        — سيتم استخدامه كامتداد لصفحة المشروع.
                    </flux:description>

                    <flux:error name="domain" />

                </flux:field>


                {{-- Phone --}}

                <flux:field>

                    <flux:label>
                        رقم الهاتف
                    </flux:label>

                    <flux:input
                        wire:model="phone"
                        placeholder="059xxxxxxx"
                        autocomplete="tel"
                        dir="ltr"
                    />

                    <flux:error name="phone" />

                </flux:field>


                {{-- Address --}}

                <flux:field>

                    <flux:label>
                        العنوان
                    </flux:label>

                    <flux:input
                        wire:model="address"
                        placeholder="المدينة، الشارع، المنطقة..."
                        autocomplete="street-address"
                    />

                    <flux:error name="address" />

                </flux:field>

            </div>


            {{-- ========================================================
                PREVIEW
            ========================================================= --}}

            <div
                class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950/40"
            >

                <div class="flex items-center gap-3">

                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-xl
                        {{
                            $type === 'branch'
                                ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400'
                                : 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-400'
                        }}"
                    >

                        @if ($type === 'branch')

                            <flux:icon.building-storefront class="size-5" />

                        @else

                            <flux:icon.archive-box class="size-5" />

                        @endif

                    </div>


                    <div class="min-w-0 flex-1">

                        <div class="text-xs text-zinc-500">
                            معاينة صفحة المشروع
                        </div>


                        <div class="truncate text-sm font-medium text-zinc-900 dark:text-white">

                            {{
                                filled($name)
                                    ? $name
                                    : 'اسم الموقع'
                            }}

                        </div>


                        @if (filled($domain))

                            <div
                                dir="ltr"
                                class="mt-1 truncate text-sm font-bold text-zinc-700 dark:text-zinc-200"
                            >
                                /{{ $domain }}
                            </div>

                        @else

                            <div class="mt-1 text-xs text-zinc-400">
                                لم يتم تحديد الامتداد
                            </div>

                        @endif


                        <div class="mt-1 text-xs text-zinc-500">

                            {{
                                $type === 'branch'
                                    ? 'فرع بيع'
                                    : 'مخزن'
                            }}

                        </div>

                    </div>

                </div>

            </div>


            {{-- ========================================================
                FOOTER
            ========================================================= --}}

            <div
                class="flex flex-col-reverse gap-2 border-t border-zinc-100 pt-5 sm:flex-row sm:justify-end dark:border-zinc-800"
            >

                <flux:button
                    type="button"
                    variant="ghost"
                    wire:click="closeModal"
                    wire:loading.attr="disabled"
                >
                    إلغاء
                </flux:button>


                <flux:button
                    type="submit"
                    variant="primary"
                    icon="{{ $isEditing ? 'check' : 'plus' }}"
                    wire:loading.attr="disabled"
                    wire:target="save"
                >

                    <span
                        wire:loading.remove
                        wire:target="save"
                    >
                        {{
                            $isEditing
                                ? 'حفظ التعديلات'
                                : 'إضافة الموقع'
                        }}
                    </span>


                    <span
                        wire:loading
                        wire:target="save"
                        class="inline-flex items-center gap-2"
                    >

                        <flux:icon.arrow-path class="size-4 animate-spin" />

                        جاري الحفظ...

                    </span>

                </flux:button>

            </div>

        </form>

    </flux:modal>

</flux:main>
