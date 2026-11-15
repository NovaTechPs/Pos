<?php
use App\Models\Tenant;
use App\Models\WebCategory;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
new class extends Component
{
    use WithFileUploads;
    #[Locked]
    public ?int $editingId = null;
    public string $name = '';
    public int $sortOrder = 0;
    public bool $isActive = true;
    public $image = null;
    private function tenantId(): int
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('store.settings.manage'), 403);
        return (int) Tenant::query()->whereKey(session('active_tenant_id'))->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('id', $user->tenant_id))->firstOrFail()->id;
    }
    public function edit(int $id): void
    {
        $category = WebCategory::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->sortOrder = $category->sort_order;
        $this->isActive = $category->is_active;
        $this->reset('image');
    }
    public function cancel(): void
    {
        $this->tenantId();
        $this->reset('editingId', 'name', 'sortOrder', 'isActive', 'image');
        $this->resetValidation();
    }
    public function saveCategory(): void
    {
        $tenantId = $this->tenantId();
        $this->name = trim($this->name);
        $this->validate(['name' => ['required', 'string', 'max:100', Rule::unique('web_categories')->where('tenant_id', $tenantId)->ignore($this->editingId)],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'], 'isActive' => ['boolean'], 'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $category = $this->editingId ? WebCategory::query()->where('tenant_id', $tenantId)->findOrFail($this->editingId) : new WebCategory(['tenant_id' => $tenantId]);
        $path = null;
        try {
            if ($this->image) { $path = $this->image->store('tenants/'.$tenantId.'/web-categories', 'public'); }
            $category->fill(['name' => $this->name, 'sort_order' => $this->sortOrder, 'is_active' => $this->isActive]);
            if ($path) { $category->image = $path; }
            $category->save();
        } catch (Throwable $exception) {
            if ($path) { Storage::disk('public')->delete($path); }
            throw $exception;
        }
        $this->cancel();
        session()->flash('category_saved', 'تم حفظ قسم الويب.');
    }
    public function delete(int $id): void
    {
        $tenantId = $this->tenantId();
        DB::transaction(function () use ($id, $tenantId): void {
            $category = WebCategory::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('web_category_id', $id)->update(['web_category_id' => null]);
            $category->delete();
        });
        $this->cancel();
    }
    public function render(): mixed
    {
        return $this->view(['categories' => WebCategory::query()->where('tenant_id', $this->tenantId())->orderBy('sort_order')->orderBy('name')->get()]);
    }
};
?>
<section id="web-categories" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
    <flux:heading size="lg">أقسام الويب</flux:heading>
    <flux:text class="mt-1">تصنيفات مستقلة لواجهة المتجر. اختر قسم الويب داخل بطاقة الصنف؛ لا يتغير تصنيفه الداخلي.</flux:text>
    @if(session('category_saved'))<p role="status" class="mt-3 text-sm text-emerald-600">{{ session('category_saved') }}</p>@endif
    <div class="mt-4 grid gap-5 lg:grid-cols-2">
        <form wire:submit="saveCategory" class="space-y-3">
            <flux:input wire:model="name" label="اسم القسم" maxlength="100" />
            <flux:input wire:model="sortOrder" type="number" min="0" max="9999" label="ترتيب العرض" />
            <flux:switch wire:model="isActive" label="إظهار القسم في المتجر" />
            <label class="block text-sm">صورة القسم<input type="file" wire:model="image" accept="image/png,image/jpeg,image/webp" class="mt-2 block w-full"></label>
            <flux:error name="image" />
            @if($image && $image->isPreviewable())<img src="{{ $image->temporaryUrl() }}" alt="معاينة القسم" class="size-20 rounded-lg object-cover">@endif
            <div class="flex gap-2"><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveCategory,image">{{ $editingId ? 'حفظ التعديل' : 'إضافة قسم' }}</flux:button>@if($editingId)<flux:button type="button" wire:click="cancel">إلغاء</flux:button>@endif</div>
        </form>
        <div class="space-y-2">
            @forelse($categories as $category)
                <div wire:key="web-category-{{ $category->id }}" class="flex items-center gap-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                    @if($category->image)<img src="{{ Storage::disk('public')->url($category->image) }}" alt="" class="size-12 rounded-lg object-cover">@endif
                    <div class="min-w-0 flex-1"><strong>{{ $category->name }}</strong><p class="text-xs text-zinc-500">ترتيب {{ $category->sort_order }} · {{ $category->is_active ? 'ظاهر' : 'مخفي' }}</p></div>
                    <flux:button size="sm" wire:click="edit({{ $category->id }})">تعديل</flux:button>
                    <flux:button size="sm" variant="danger" wire:click="delete({{ $category->id }})" wire:confirm="حذف قسم الويب؟ ستبقى الأصناف في المتجر دون هذا القسم.">حذف</flux:button>
                </div>
            @empty<p class="py-6 text-center text-sm text-zinc-500">أضف أول قسم لمتجرك الإلكتروني.</p>@endforelse
        </div>
    </div>
</section>
