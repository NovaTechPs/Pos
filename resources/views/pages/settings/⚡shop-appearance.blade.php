<?php
use App\Models\Tenant;
use App\Models\Branch;
use App\Services\OnlineShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
new class extends Component
{
    use WithFileUploads;
    public ?int $branchId = null;
    public string $storeName = '';
    public string $phone = '';
    public string $primaryColor = '#064b9b';
    public string $accentColor = '#ffd336';
    public string $tagline = '';
    public string $heroTitle = '';
    public string $heroText = '';
    public $logo = null;
    public $banner = null;
    private function tenantId(): int
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('store.settings.manage'), 403);
        return (int) Tenant::query()->whereKey(session('active_tenant_id'))->where(fn ($query) => $query->where('owner_id', $user->id)->orWhere('id', $user->tenant_id))->firstOrFail()->id;
    }
    public function mount(): void
    {
        $this->branchId = $this->branches()->whereKey(session('active_branch_id'))->value('id')
            ?? $this->branches()->orderBy('id')->value('id');
        $this->loadAppearance();
    }

    /** @return Builder<Branch> */
    private function branches(): Builder
    {
        return Branch::query()->where('tenant_id', $this->tenantId());
    }

    private function selectedBranch(): Branch
    {
        return $this->branches()->findOrFail($this->branchId);
    }

    public function updatedBranchId(): void
    {
        $this->selectedBranch();
        $this->reset('logo', 'banner');
        $this->resetValidation();
        session()->forget('appearance_saved');
        $this->loadAppearance();
    }

    private function loadAppearance(): void
    {
        if ($this->branchId === null) {
            return;
        }
        $branding = app(OnlineShop::class)->branding($this->selectedBranch());
        $this->storeName = $branding['name'];
        $this->phone = $branding['phone'];
        $this->tagline = $branding['tagline'];
        $this->heroTitle = $branding['hero_title'];
        $this->heroText = $branding['hero_text'];
        $this->primaryColor = $branding['primary_color'];
        $this->accentColor = $branding['accent_color'];
    }
    public function saveAppearance(): void
    {
        $branch = $this->selectedBranch();
        $this->validate(['storeName' => ['required', 'string', 'max:100'], 'phone' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9()\s-]*$/D'],
            'primaryColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'], 'accentColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'tagline' => ['nullable', 'string', 'max:100'], 'heroTitle' => ['required', 'string', 'max:100'], 'heroText' => ['nullable', 'string', 'max:250'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'], 'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $paths = [];
        try {
            $directory = 'tenants/'.$branch->tenant_id.'/branches/'.$branch->id.'/shop';
            if ($this->logo) { $paths['shop_logo'] = $this->logo->store($directory, 'public'); }
            if ($this->banner) { $paths['shop_banner'] = $this->banner->store($directory, 'public'); }
            DB::transaction(function () use ($paths): void {
                $branch = $this->branches()->lockForUpdate()->findOrFail($this->branchId);
                $branch->update(['shop_branding' => array_replace($branch->shop_branding ?? [], [
                    'shop_name' => $this->storeName, 'shop_phone' => $this->phone,
                    'shop_primary_color' => $this->primaryColor, 'shop_accent_color' => $this->accentColor,
                    'shop_tagline' => $this->tagline, 'shop_hero_title' => $this->heroTitle, 'shop_hero_text' => $this->heroText,
                ], $paths)]);
            });
        } catch (Throwable $exception) {
            foreach ($paths as $path) { Storage::disk('public')->delete($path); }
            throw $exception;
        }
        $this->reset('logo', 'banner');
        session()->flash('appearance_saved', 'تم حفظ هوية متجر الفرع المحدد فقط.');
    }
    public function removeImage(string $kind): void
    {
        abort_unless(in_array($kind, ['logo', 'banner'], true), 404);
        DB::transaction(function () use ($kind): void {
            $branch = $this->branches()->lockForUpdate()->findOrFail($this->branchId);
            $branch->update(['shop_branding' => array_replace($branch->shop_branding ?? [], ['shop_'.$kind => null])]);
        });
        $this->reset($kind);
    }

    public function resetAppearance(): void
    {
        $this->selectedBranch()->update(['shop_branding' => null]);
        $this->reset('logo', 'banner');
        $this->resetValidation();
        $this->loadAppearance();
        session()->flash('appearance_saved', 'تمت استعادة الهوية الافتراضية لهذا الفرع.');
    }
    public function render(): mixed
    {
        $branch = $this->branchId === null ? null : $this->selectedBranch();
        $branding = $branch ? app(OnlineShop::class)->branding($branch) : null;
        return $this->view(['branches' => $this->branches()->orderBy('name')->get(), 'selectedBranch' => $branch,
            'logoPath' => $branding['logo'] ?? null, 'bannerPath' => $branding['banner'] ?? null]);
    }
};
?>
<section id="shop-appearance" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
    <flux:heading size="lg">هوية متجر كل فرع</flux:heading>
    <flux:text class="mt-1">اختر الفرع لتخصيص واجهته. الفروع غير المخصصة تستخدم الهوية الافتراضية، والأسعار والمخزون حسب الفرع.</flux:text>
    <div class="mt-4">
        <flux:select wire:model.live="branchId" label="الفرع الذي تريد تخصيص متجره">
            @forelse($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@empty<option value="">لا توجد فروع</option>@endforelse
        </flux:select>
    </div>
    @if($selectedBranch)
    <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
        <span>{{ $selectedBranch->shop_branding === null ? 'يستخدم الهوية الافتراضية' : 'هوية خاصة بهذا الفرع' }}</span>
        @if($selectedBranch->domain)<a href="{{ route('shop.home', ['shop' => $selectedBranch->domain]) }}" target="_blank" rel="noopener" class="text-blue-600 underline">معاينة متجر الفرع</a>@endif
    </div>
    @if(session('appearance_saved'))<p role="status" class="mt-3 text-sm text-emerald-600">{{ session('appearance_saved') }}</p>@endif
    <form wire:submit="saveAppearance" class="mt-4 space-y-4" wire:key="appearance-{{ $branchId }}">
        <div class="grid gap-4 md:grid-cols-2">
            <flux:input wire:model="storeName" label="اسم المتجر الظاهر للزبون" maxlength="100" />
            <flux:input wire:model="phone" label="رقم التواصل لهذا المتجر" maxlength="30" dir="ltr" />
            <flux:input type="color" wire:model.live="primaryColor" label="اللون الرئيسي" />
            <flux:input type="color" wire:model.live="accentColor" label="لون الأزرار المميزة" />
        </div>
        <div class="rounded-xl p-5" style="background: {{ OnlineShop::brandColor($primaryColor, '#064b9b') }}; color: white">
            <strong>{{ $storeName }}</strong><p>{{ $tagline }}</p>
            <span class="mt-3 inline-block rounded-lg px-4 py-2 text-black" style="background: {{ OnlineShop::brandColor($accentColor, '#ffd336') }}">معاينة الألوان</span>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach (['logo' => ['شعار المحل', $logoPath, '2 ميغابايت كحد أقصى؛ صورة مربعة بخلفية شفافة مناسبة.'], 'banner' => ['بانر المتجر', $bannerPath, '5 ميغابايت كحد أقصى؛ صورة أفقية عريضة مناسبة.']] as $kind => [$label, $path, $hint])
                <div wire:key="shop-upload-{{ $kind }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <label class="block text-sm font-bold">{{ $label }}<input type="file" wire:model="{{ $kind }}" accept="image/png,image/jpeg,image/webp" class="mt-2 block w-full text-sm"></label>
                    <p class="mt-2 text-xs text-zinc-500">{{ $hint }}</p>
                    @if ($this->{$kind} && $this->{$kind}->isPreviewable())<img src="{{ $this->{$kind}->temporaryUrl() }}" alt="معاينة {{ $label }}" class="mt-3 h-28 w-full rounded-lg object-contain">
                    @elseif($path)<img src="{{ $path }}" alt="{{ $label }}" class="mt-3 h-28 w-full rounded-lg object-contain">@endif
                    @if($path)<button type="button" wire:click="removeImage('{{ $kind }}')" class="mt-2 text-xs text-rose-600">إزالة الصورة الحالية</button>@endif
                    <flux:error name="{{ $kind }}" />
                </div>
            @endforeach
        </div>
        <flux:input wire:model="tagline" label="وصف قصير تحت اسم المحل" maxlength="100" />
        <flux:input wire:model="heroTitle" label="عنوان البانر" maxlength="100" />
        <flux:textarea wire:model="heroText" label="نص البانر" rows="2" maxlength="250" />
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveAppearance,logo,banner">حفظ هوية المتجر</flux:button>
        <flux:button type="button" wire:click="resetAppearance" wire:confirm="استعادة الهوية الافتراضية لهذا الفرع فقط؟">استعادة الهوية الافتراضية</flux:button>
    </form>
    @endif
</section>
