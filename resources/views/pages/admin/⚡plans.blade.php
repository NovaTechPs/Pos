<?php

use Livewire\Component;
use App\Models\Plan;
use App\Models\PlanFeature;

new class extends Component {
    public $plans;
    public $plan_id;
    public string $name = '';
    public string $slug = '';
    public $price = '';
    public string $invoice_period = 'monthly';
    public $invoice_interval = 1;
    public string $description = '';

    // الصلاحيات/الميزات المحددة للباقة
    public array $selectedFeatures = []; // [ 'pos_system' => true, 'multi_branch' => true ]
    public array $featureLimits = []; // [ 'max_products' => 100 ]

    public bool $showModal = false;
    public bool $isEditing = false;

    // تعريف قائمة الميزات المتاحة في النظام
    public function getAvailableFeaturesProperty()
    {
        return [
            'pos_system' => ['label' => 'نظام الكاشير (POS)', 'type' => 'boolean'],
            'multi_branch' => ['label' => 'تعدد الفروع', 'type' => 'boolean'],
            'advanced_reports' => ['label' => 'التقارير المتقدمة', 'type' => 'boolean'],
            'max_products' => ['label' => 'الحد الأقصى للمنتجات (-1 لغير محدود)', 'type' => 'limit'],
        ];
    }

    protected function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'slug' => 'required|string|max:255|unique:plans,slug,' . $this->plan_id,
            'invoice_period' => 'required|in:daily,weekly,monthly,yearly',
            'invoice_interval' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'selectedFeatures' => 'nullable|array',
            'featureLimits' => 'nullable|array',
        ];
    }

    public function mount()
    {
        $this->loadPlans();
    }

    public function loadPlans()
    {
        $this->plans = Plan::with(['features'])
            ->withCount('tenants')
            ->get();
    }

    public function openCreateModal()
    {
        $this->resetInputFields();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function edit($id)
    {
        $plan = Plan::with('features')->findOrFail($id);
        $this->plan_id = $plan->id;
        $this->name = $plan->name;
        $this->slug = $plan->slug;
        $this->price = $plan->price;
        $this->invoice_period = $plan->invoice_period ?? 'monthly';
        $this->invoice_interval = $plan->invoice_interval ?? 1;
        $this->description = $plan->description ?? '';

        // تحميل الميزات الخاصة بهذه الباقة
        $this->selectedFeatures = [];
        $this->featureLimits = [];

        foreach ($plan->features as $feature) {
            if ($feature->value === 'true') {
                $this->selectedFeatures[$feature->feature_key] = true;
            } else {
                $this->featureLimits[$feature->feature_key] = $feature->value;
            }
        }

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function save()
    {
        $validatedData = $this->validate();

        $plan = Plan::updateOrCreate(
            ['id' => $this->plan_id],
            [
                'name' => $this->name,
                'slug' => $this->slug,
                'price' => $this->price,
                'invoice_period' => $this->invoice_period,
                'invoice_interval' => $this->invoice_interval,
                'description' => $this->description,
            ],
        );

        // إعادة حفظ الميزات والحدود
        $plan->features()->delete(); // مسح القديم لتحديث البيانات

        // 1. حفظ ميزات التفعيل On/Off
        foreach ($this->selectedFeatures as $key => $value) {
            if ($value) {
                $plan->features()->create([
                    'feature_key' => $key,
                    'value' => 'true',
                ]);
            }
        }

        // 2. حفظ حدود الميزات Num Limits
        foreach ($this->featureLimits as $key => $value) {
            if ($value !== '' && $value !== null) {
                $plan->features()->create([
                    'feature_key' => $key,
                    'value' => (string) $value,
                ]);
            }
        }

        session()->flash('message', $this->isEditing ? 'تم تحديث الباقة بنجاح.' : 'تم إنشاء الباقة بنجاح.');

        $this->closeModal();
        $this->loadPlans();
    }

    public function delete($id)
    {
        Plan::findOrFail($id)->delete();
        session()->flash('message', 'تم حذف الباقة بنجاح.');
        $this->loadPlans();
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetInputFields();
    }

    private function resetInputFields()
    {
        $this->plan_id = null;
        $this->name = '';
        $this->slug = '';
        $this->price = '';
        $this->invoice_period = 'monthly';
        $this->invoice_interval = 1;
        $this->description = '';
        $this->selectedFeatures = [];
        $this->featureLimits = [];
        $this->resetValidation();
    }

    public function render()
    {
        return $this->view()->layout('layouts::saas');
    }
};
?>

{{-- غلاف رئيسي واحد لمنع خطأ Multiple Root Elements --}}

<flux:main class="space-y-6">
    <!-- الهيدر العلوي -->
    <div
        class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-zinc-200 dark:border-zinc-800">
        <div>
            <flux:heading size="xl" level="1">إدارة الباقات والصلاحيات</flux:heading>
            <flux:subheading>إدارة وتعديل باقات الاشتراك والميزات المتاحة المخصصة لكل خطة</flux:subheading>
        </div>
        <div>
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                إضافة باقة جديدة
            </flux:button>
        </div>
    </div>

    <!-- تنبيه النجاح -->
    @if (session()->has('message'))
        <flux:badge variant="success" class="w-full justify-start p-3 text-sm">
            {{ session('message') }}
        </flux:badge>
    @endif

    <!-- جدول العرض المحسن -->
    <flux:card class="p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>اسم الباقة</flux:table.column>
                <flux:table.column>الرابط (Slug)</flux:table.column>
                <flux:table.column>السعر</flux:table.column>
                <flux:table.column>فترة الفاتورة</flux:table.column>
                <flux:table.column>الميزات المفعلة</flux:table.column>
                <flux:table.column align="center">المشتركين</flux:table.column>
                <flux:table.column align="end">الإجراءات</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse($plans as $plan)
                    <flux:table.row key="{{ $plan->id }}">
                        <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                            {{ $plan->name }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" color="zinc">{{ $plan->slug }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="font-semibold text-emerald-600 dark:text-emerald-400">
                            ${{ number_format($plan->price, 2) }}
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $plan->invoice_interval }} {{ $plan->invoice_period }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @forelse($plan->features as $feature)
                                    <flux:badge size="sm" variant="subtle" color="indigo">
                                        {{ $feature->feature_key }}: {{ $feature->value }}
                                    </flux:badge>
                                @empty
                                    <span class="text-xs text-zinc-400">لا توجد ميزات</span>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="center">
                            <flux:badge size="sm" variant="outline">{{ $plan->tenants_count ?? 0 }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button variant="ghost" size="sm" icon="pencil-square"
                                    wire:click="edit({{ $plan->id }})" />
                                <flux:button variant="ghost" size="sm" icon="trash"
                                    class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30"
                                    wire:click="delete({{ $plan->id }})"
                                    wire:confirm="هل أنت تأكد من عرض حذف هذه الباقة؟" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" align="center" class="py-8 text-zinc-500">
                            لا توجد باقات مضافة بعد.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <!-- المودال الاحترافي المتوافق مع Flux -->
    <flux:modal wire:model="showModal" class="md:w-180 space-y-6">
        <div>
            <flux:heading size="lg">{{ $isEditing ? 'تعديل الباقة' : 'إضافة باقة جديدة' }}</flux:heading>
            <flux:subheading>أدخل تفاصيل الباقة، الأسعار، وحدد الصلاحيات والميزات المتاحة لها</flux:subheading>
        </div>

        <form wire:submit.prevent="save" class="space-y-4">
            <flux:field>
                <flux:label>اسم الباقة</flux:label>
                <flux:input wire:model="name" placeholder="مثال: الباقة الاحترافية" />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>الرابط الفريد (Slug)</flux:label>
                <flux:input wire:model="slug" placeholder="pro-plan" />
                <flux:error name="slug" />
            </flux:field>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>السعر ($)</flux:label>
                    <flux:input type="number" step="0.01" wire:model="price" placeholder="29.99" />
                    <flux:error name="price" />
                </flux:field>
                <flux:field>
                    <flux:label>دورية الفاتورة</flux:label>
                    <flux:select wire:model="invoice_period">
                        <flux:select.option value="monthly">شهري</flux:select.option>
                        <flux:select.option value="yearly">سنوي</flux:select.option>
                        <flux:select.option value="weekly">أسبوعي</flux:select.option>
                        <flux:select.option value="daily">يومي</flux:select.option>
                    </flux:select>
                    <flux:error name="invoice_period" />
                </flux:field>

            </div>

            <flux:field>
                <flux:label>الوصف</flux:label>
                <flux:textarea wire:model="description" placeholder="اكتب وصفاً قصيراً لمميزات هذه الباقة..."
                    rows="2" />
                <flux:error name="description" />
            </flux:field>

            <!-- قسم إدارة صلاحيات وميزات الباقة -->
            <div class="pt-4 border-t border-zinc-200 dark:border-zinc-800 space-y-3">
                <flux:heading size="sm">صلاحيات وميزات الخطة</flux:heading>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach ($this->availableFeatures as $key => $feature)
                        @if ($feature['type'] === 'boolean')
                            <div class="flex items-center p-3 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                                <flux:checkbox wire:model="selectedFeatures.{{ $key }}"
                                    :label="$feature['label']" />
                            </div>
                        @elseif($feature['type'] === 'limit')
                            <flux:field class="sm:col-span-2">
                                <flux:label>{{ $feature['label'] }}</flux:label>
                                <flux:input type="number" wire:model="featureLimits.{{ $key }}"
                                    placeholder="مثال: 100 أو -1" />
                            </flux:field>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="closeModal">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">حفظ البيانات والصلاحيات</flux:button>
            </div>
        </form>
    </flux:modal>
</flux:main>
