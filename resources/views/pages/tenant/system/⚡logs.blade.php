<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\AuditLog;

new class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $action = '';
    public string $modelType = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    public ?int $selectedLogId = null;


    /*
    |--------------------------------------------------------------------------
    | Reset Pagination
    |--------------------------------------------------------------------------
    */

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingAction(): void
    {
        $this->resetPage();
    }

    public function updatingModelType(): void
    {
        $this->resetPage();
    }

    public function updatingDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatingDateTo(): void
    {
        $this->resetPage();
    }


    /*
    |--------------------------------------------------------------------------
    | Reset Filters
    |--------------------------------------------------------------------------
    */

    public function resetFilters(): void
    {
        $this->search = '';
        $this->action = '';
        $this->modelType = '';
        $this->dateFrom = '';
        $this->dateTo = '';

        $this->resetPage();
    }


    /*
    |--------------------------------------------------------------------------
    | Show Details
    |--------------------------------------------------------------------------
    */

    public function showDetails(int $id): void
    {
        $this->selectedLogId = $id;
    }


    /*
    |--------------------------------------------------------------------------
    | Close Details
    |--------------------------------------------------------------------------
    */

    public function closeDetails(): void
    {
        $this->selectedLogId = null;
    }


    /*
    |--------------------------------------------------------------------------
    | Selected Log
    |--------------------------------------------------------------------------
    */

    public function getSelectedLogProperty()
    {
        if (!$this->selectedLogId) {
            return null;
        }

        $tenantId = session('active_tenant_id');

        return AuditLog::query()
            ->with('user')
            ->when($tenantId, function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId);
            })
            ->find($this->selectedLogId);
    }


    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    */

    public function getLogsProperty()
    {
        $tenantId = session('active_tenant_id');

        return AuditLog::query()
            ->with('user')

            ->when($tenantId, function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId);
            })

            ->when($this->search !== '', function ($query) {

                $search = trim($this->search);

                $query->where(function ($q) use ($search) {

                    $q->where('id', $search)

                        ->orWhere('action', 'ilike', "%{$search}%")

                        ->orWhere(
                            'subject_type',
                            'ilike',
                            "%{$search}%"
                        )

                        ->orWhere(
                            'subject_id',
                            'ilike',
                            "%{$search}%"
                        )

                        ->orWhereHas('user', function ($userQuery) use ($search) {

                            $userQuery
                                ->where(
                                    'name',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'email',
                                    'ilike',
                                    "%{$search}%"
                                );

                        });

                });

            })

            ->when($this->action !== '', function ($query) {

                $query->where('action', $this->action);

            })

            ->when($this->modelType !== '', function ($query) {

                $query->where(
                    'subject_type',
                    'ilike',
                    "%{$this->modelType}%"
                );

            })

            ->when($this->dateFrom !== '', function ($query) {

                $query->whereDate(
                    'created_at',
                    '>=',
                    $this->dateFrom
                );

            })

            ->when($this->dateTo !== '', function ($query) {

                $query->whereDate(
                    'created_at',
                    '<=',
                    $this->dateTo
                );

            })

            ->latest('id')
            ->paginate(20);
    }


    /*
    |--------------------------------------------------------------------------
    | Action Label
    |--------------------------------------------------------------------------
    */

    public function getActionLabel(?string $action): string
    {
        return match ($action) {

            'created' => 'إنشاء',

            'updated' => 'تعديل',

            'deleted' => 'حذف',

            'restored' => 'استعادة',

            null,
            '' => 'غير محدد',

            default => $action,

        };
    }


    /*
    |--------------------------------------------------------------------------
    | Action Color
    |--------------------------------------------------------------------------
    */

    public function getActionClass(?string $action): string
    {
        return match ($action) {

            'created' =>
                'bg-green-100 text-green-700',

            'updated' =>
                'bg-blue-100 text-blue-700',

            'deleted' =>
                'bg-red-100 text-red-700',

            'restored' =>
                'bg-yellow-100 text-yellow-700',

            null,
            '' =>
                'bg-gray-100 text-gray-500',

            default =>
                'bg-gray-100 text-gray-700',

        };
    }


    /*
    |--------------------------------------------------------------------------
    | Model Name
    |--------------------------------------------------------------------------
    */

    public function getModelName(?string $type): string
    {
        if (!$type) {
            return 'غير محدد';
        }

        return match (class_basename($type)) {

            'Product' =>
                'المنتج',

            'Order' =>
                'الفاتورة',

            'OrderItem' =>
                'تفاصيل الفاتورة',

            'Party' =>
                'العميل / المورد',

            'Payment' =>
                'السند',

            'Branch' =>
                'الفرع',

            'Category' =>
                'التصنيف',

            'User' =>
                'المستخدم',

            default =>
                class_basename($type),

        };
    }
      public function render()
    {

        return $this->view()->layout('layouts::tenant');
    }
};
?>
<flux:main >

<div dir="rtl" class="p-4 sm:p-6">


    {{-- ========================================================= --}}
    {{-- العنوان --}}
    {{-- ========================================================= --}}

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

        <div>

            <h1 class="text-2xl font-bold text-gray-800">
                سجل العمليات
            </h1>

            <p class="mt-1 text-sm text-gray-500">
                متابعة جميع العمليات التي تمت داخل المتجر
            </p>

        </div>


        <div class="text-sm text-gray-500">

            إجمالي السجلات:

            <span class="font-bold text-gray-800">
                {{ $this->logs->total() }}
            </span>

        </div>

    </div>



    {{-- ========================================================= --}}
    {{-- الفلاتر --}}
    {{-- ========================================================= --}}

    <div class="mb-5 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-5">


            {{-- البحث --}}

            <div class="lg:col-span-2">

                <label class="mb-1 block text-sm font-medium text-gray-700">
                    بحث
                </label>

                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="ابحث بالمستخدم أو رقم السجل..."
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                >

            </div>



            {{-- العملية --}}

            <div>

                <label class="mb-1 block text-sm font-medium text-gray-700">
                    العملية
                </label>

                <select
                    wire:model.live="action"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                >

                    <option value="">
                        جميع العمليات
                    </option>

                    <option value="created">
                        إنشاء
                    </option>

                    <option value="updated">
                        تعديل
                    </option>

                    <option value="deleted">
                        حذف
                    </option>

                    <option value="restored">
                        استعادة
                    </option>

                </select>

            </div>



            {{-- النوع --}}

            <div>

                <label class="mb-1 block text-sm font-medium text-gray-700">
                    نوع السجل
                </label>

                <select
                    wire:model.live="modelType"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                >

                    <option value="">
                        جميع الأنواع
                    </option>

                    <option value="Product">
                        المنتجات
                    </option>

                    <option value="Order">
                        الفواتير
                    </option>

                    <option value="Party">
                        العملاء والموردين
                    </option>

                    <option value="Payment">
                        السندات
                    </option>

                    <option value="Branch">
                        الفروع
                    </option>

                    <option value="Category">
                        التصنيفات
                    </option>

                    <option value="User">
                        المستخدمين
                    </option>

                </select>

            </div>



            {{-- من تاريخ --}}

            <div>

                <label class="mb-1 block text-sm font-medium text-gray-700">
                    من تاريخ
                </label>

                <input
                    type="date"
                    wire:model.live="dateFrom"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                >

            </div>



            {{-- إلى تاريخ --}}

            <div>

                <label class="mb-1 block text-sm font-medium text-gray-700">
                    إلى تاريخ
                </label>

                <input
                    type="date"
                    wire:model.live="dateTo"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                >

            </div>

        </div>



        {{-- زر مسح الفلاتر --}}

        @if(
            $search ||
            $action ||
            $modelType ||
            $dateFrom ||
            $dateTo
        )

            <div class="mt-3">

                <button
                    type="button"
                    wire:click="resetFilters"
                    class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                >
                    مسح الفلاتر
                </button>

            </div>

        @endif

    </div>



    {{-- ========================================================= --}}
    {{-- الجدول --}}
    {{-- ========================================================= --}}

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">

        <div class="overflow-x-auto">

            <table class="min-w-full text-right text-sm">

                <thead class="bg-gray-50 text-gray-600">

                    <tr>

                        <th class="whitespace-nowrap px-4 py-3 font-semibold">
                            #
                        </th>

                        <th class="whitespace-nowrap px-4 py-3 font-semibold">
                            المستخدم
                        </th>

                        <th class="whitespace-nowrap px-4 py-3 font-semibold">
                            العملية
                        </th>

                        <th class="whitespace-nowrap px-4 py-3 font-semibold">
                            النوع
                        </th>

                        <th class="whitespace-nowrap px-4 py-3 font-semibold">
                            رقم السجل
                        </th>

                        <th class="whitespace-nowrap px-4 py-3 font-semibold">
                            التاريخ
                        </th>

                        <th class="whitespace-nowrap px-4 py-3 font-semibold">
                            التفاصيل
                        </th>

                    </tr>

                </thead>



                <tbody class="divide-y divide-gray-100">

                    @forelse($this->logs as $log)

                        <tr
                            wire:key="audit-log-{{ $log->id }}"
                            class="hover:bg-gray-50"
                        >


                            {{-- ID --}}

                            <td class="px-4 py-3 text-gray-500">

                                {{ $log->id }}

                            </td>



                            {{-- المستخدم --}}

                            <td class="px-4 py-3">

                                <div class="font-medium text-gray-800">

                                    {{ $log->user?->name ?? 'النظام' }}

                                </div>

                                @if($log->user?->email)

                                    <div class="text-xs text-gray-400">

                                        {{ $log->user->email }}

                                    </div>

                                @endif

                            </td>



                            {{-- العملية --}}

                            <td class="px-4 py-3">

                                <span
                                    class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $this->getActionClass($log->action) }}"
                                >

                                    {{ $this->getActionLabel($log->action) }}

                                </span>

                            </td>



                            {{-- النوع --}}

                            <td class="px-4 py-3 text-gray-700">

                                {{ $this->getModelName($log->subject_type) }}

                            </td>



                            {{-- رقم السجل --}}

                            <td class="px-4 py-3 font-mono text-gray-600">

                                #{{ $log->subject_id }}

                            </td>



                            {{-- التاريخ --}}

                            <td class="whitespace-nowrap px-4 py-3 text-gray-600">

                                {{ $log->created_at?->format('Y-m-d') }}

                                <div class="text-xs text-gray-400">

                                    {{ $log->created_at?->format('H:i:s') }}

                                </div>

                            </td>



                            {{-- التفاصيل --}}

                            <td class="px-4 py-3">

                                <button
                                    type="button"
                                    wire:click="showDetails({{ $log->id }})"
                                    class="rounded-lg bg-gray-100 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-200"
                                >

                                    عرض التفاصيل

                                </button>

                            </td>

                        </tr>


                    @empty

                        <tr>

                            <td
                                colspan="7"
                                class="px-4 py-12 text-center text-gray-500"
                            >

                                لا توجد عمليات مسجلة

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>



        {{-- Pagination --}}

        @if($this->logs->hasPages())

            <div class="border-t border-gray-100 px-4 py-3">

                {{ $this->logs->links() }}

            </div>

        @endif

    </div>



    {{-- ========================================================= --}}
    {{-- نافذة التفاصيل --}}
    {{-- ========================================================= --}}

    @if($this->selectedLog)

        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            wire:click.self="closeDetails"
        >

            <div
                class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white shadow-2xl"
            >


                {{-- Header --}}

                <div class="flex items-center justify-between border-b px-5 py-4">

                    <div>

                        <h2 class="text-lg font-bold text-gray-800">
                            تفاصيل العملية
                        </h2>

                        <p class="text-xs text-gray-500">
                            سجل رقم #{{ $this->selectedLog->id }}
                        </p>

                    </div>


                    <button
                        type="button"
                        wire:click="closeDetails"
                        class="rounded-lg px-3 py-2 text-xl text-gray-500 hover:bg-gray-100"
                    >

                        ×

                    </button>

                </div>



                {{-- معلومات العملية --}}

                <div class="grid grid-cols-1 gap-4 p-5 md:grid-cols-2">


                    {{-- المستخدم --}}

                    <div class="rounded-lg bg-gray-50 p-4">

                        <div class="text-xs text-gray-500">
                            المستخدم
                        </div>

                        <div class="mt-1 font-semibold text-gray-800">

                            {{ $this->selectedLog->user?->name ?? 'النظام' }}

                        </div>

                    </div>



                    {{-- العملية --}}

                    <div class="rounded-lg bg-gray-50 p-4">

                        <div class="text-xs text-gray-500">
                            العملية
                        </div>

                        <div class="mt-1">

                            <span
                                class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $this->getActionClass($this->selectedLog->action) }}"
                            >

                                {{ $this->getActionLabel($this->selectedLog->action) }}

                            </span>

                        </div>

                    </div>



                    {{-- النوع --}}

                    <div class="rounded-lg bg-gray-50 p-4">

                        <div class="text-xs text-gray-500">
                            نوع السجل
                        </div>

                        <div class="mt-1 font-semibold text-gray-800">

                            {{ $this->getModelName($this->selectedLog->subject_type) }}

                        </div>

                    </div>



                    {{-- رقم السجل --}}

                    <div class="rounded-lg bg-gray-50 p-4">

                        <div class="text-xs text-gray-500">
                            رقم السجل
                        </div>

                        <div class="mt-1 font-mono font-semibold text-gray-800">

                            #{{ $this->selectedLog->subject_id }}

                        </div>

                    </div>



                    {{-- التاريخ --}}

                    <div class="rounded-lg bg-gray-50 p-4 md:col-span-2">

                        <div class="text-xs text-gray-500">
                            التاريخ
                        </div>

                        <div class="mt-1 font-semibold text-gray-800">

                            {{ $this->selectedLog->created_at?->format('Y-m-d H:i:s') }}

                        </div>

                    </div>

                </div>



                {{-- ================================================= --}}
                {{-- التغييرات --}}
                {{-- ================================================= --}}

                <div class="px-5 pb-5">

                    <h3 class="mb-3 text-base font-bold text-gray-800">
                        التغييرات
                    </h3>


                    @php

                        $oldValues = $this->selectedLog->old_values ?? [];

                        $newValues = $this->selectedLog->new_values ?? [];


                        if (is_string($oldValues)) {

                            $oldValues =
                                json_decode(
                                    $oldValues,
                                    true
                                ) ?? [];

                        }


                        if (is_string($newValues)) {

                            $newValues =
                                json_decode(
                                    $newValues,
                                    true
                                ) ?? [];

                        }


                        $oldValues = is_array($oldValues)
                            ? $oldValues
                            : [];


                        $newValues = is_array($newValues)
                            ? $newValues
                            : [];


                        $keys = collect(
                            array_keys($oldValues)
                        )
                        ->merge(
                            array_keys($newValues)
                        )
                        ->unique()
                        ->values();

                    @endphp



                    @if($keys->count())

                        <div class="overflow-hidden rounded-xl border border-gray-200">

                            <table class="min-w-full text-right text-sm">

                                <thead class="bg-gray-50">

                                    <tr>

                                        <th class="px-4 py-3 font-semibold text-gray-600">
                                            الحقل
                                        </th>

                                        <th class="px-4 py-3 font-semibold text-gray-600">
                                            القيمة القديمة
                                        </th>

                                        <th class="px-4 py-3 font-semibold text-gray-600">
                                            القيمة الجديدة
                                        </th>

                                    </tr>

                                </thead>



                                <tbody class="divide-y divide-gray-100">

                                    @foreach($keys as $key)

                                        @php

                                            $oldValue =
                                                $oldValues[$key] ?? null;

                                            $newValue =
                                                $newValues[$key] ?? null;


                                            if (is_array($oldValue)) {

                                                $oldValue =
                                                    json_encode(
                                                        $oldValue,
                                                        JSON_UNESCAPED_UNICODE |
                                                        JSON_PRETTY_PRINT
                                                    );

                                            }


                                            if (is_array($newValue)) {

                                                $newValue =
                                                    json_encode(
                                                        $newValue,
                                                        JSON_UNESCAPED_UNICODE |
                                                        JSON_PRETTY_PRINT
                                                    );

                                            }

                                        @endphp


                                        <tr>


                                            {{-- الحقل --}}

                                            <td class="px-4 py-3 font-medium text-gray-700">

                                                {{ $key }}

                                            </td>



                                            {{-- القديم --}}

                                            <td class="break-all px-4 py-3 text-red-600">

                                                {{ $oldValue ?? '—' }}

                                            </td>



                                            {{-- الجديد --}}

                                            <td class="break-all px-4 py-3 text-green-600">

                                                {{ $newValue ?? '—' }}

                                            </td>


                                        </tr>

                                    @endforeach

                                </tbody>

                            </table>

                        </div>


                    @else

                        <div class="rounded-xl bg-gray-50 p-5 text-center text-sm text-gray-500">

                            لا توجد تفاصيل تغييرات لهذا السجل.

                        </div>

                    @endif

                </div>



                {{-- Footer --}}

                <div class="flex justify-end border-t bg-gray-50 px-5 py-4">

                    <button
                        type="button"
                        wire:click="closeDetails"
                        class="rounded-lg bg-gray-800 px-5 py-2 text-sm font-medium text-white hover:bg-gray-700"
                    >

                        إغلاق

                    </button>

                </div>

            </div>

        </div>

    @endif

</div>
</flux:main>
