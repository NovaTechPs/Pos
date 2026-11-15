<?php
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
new class extends Component
{
    use WithFileUploads;
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
        $id = $this->tenantId();
        $this->tagline = (string) TenantSetting::get($id, 'shop_tagline', 'كل ما تحتاجه، في مكان واحد');
        $this->heroTitle = (string) TenantSetting::get($id, 'shop_hero_title', 'اختيارات تحبّها، أقرب إليك');
        $this->heroText = (string) TenantSetting::get($id, 'shop_hero_text', 'تصفّح أقسام متجرنا، اختر ما يناسبك وأرسل طلبك بكل سهولة.');
    }
    public function saveAppearance(): void
    {
        $id = $this->tenantId();
        $this->validate(['tagline' => ['nullable', 'string', 'max:100'], 'heroTitle' => ['required', 'string', 'max:100'], 'heroText' => ['nullable', 'string', 'max:250'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'], 'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $paths = [];
        try {
            if ($this->logo) { $paths['shop_logo'] = $this->logo->store('tenants/'.$id.'/shop', 'public'); }
            if ($this->banner) { $paths['shop_banner'] = $this->banner->store('tenants/'.$id.'/shop', 'public'); }
            DB::transaction(function () use ($id, $paths): void {
                foreach (['shop_tagline' => $this->tagline, 'shop_hero_title' => $this->heroTitle, 'shop_hero_text' => $this->heroText] + $paths as $key => $value) {
                    TenantSetting::set($id, $key, $value);
                }
            });
        } catch (Throwable $exception) {
            foreach ($paths as $path) { Storage::disk('public')->delete($path); }
            throw $exception;
        }
        $this->reset('logo', 'banner');
        session()->flash('appearance_saved', 'تم حفظ هوية المتجر الإلكتروني.');
    }
    public function removeImage(string $kind): void
    {
        abort_unless(in_array($kind, ['logo', 'banner'], true), 404);
        TenantSetting::set($this->tenantId(), 'shop_'.$kind, null);
        $this->reset($kind);
    }
    public function render(): mixed
    {
        $id = $this->tenantId();
        return $this->view(['logoPath' => TenantSetting::get($id, 'shop_logo'), 'bannerPath' => TenantSetting::get($id, 'shop_banner')]);
    }
};
?>
<section id="shop-appearance" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
    <flux:heading size="lg">هوية المتجر الإلكتروني</flux:heading>
    <flux:text class="mt-1">شعار المحل والواجهة الرئيسية التي يراها المشتري. صور الشعار PNG أو JPG أو WebP.</flux:text>
    @if(session('appearance_saved'))<p role="status" class="mt-3 text-sm text-emerald-600">{{ session('appearance_saved') }}</p>@endif
    <form wire:submit="saveAppearance" class="mt-4 space-y-4">
        <div class="grid gap-4 md:grid-cols-2">
            @foreach (['logo' => ['شعار المحل', $logoPath, '2 ميغابايت كحد أقصى؛ صورة مربعة بخلفية شفافة مناسبة.'], 'banner' => ['بانر المتجر', $bannerPath, '5 ميغابايت كحد أقصى؛ صورة أفقية عريضة مناسبة.']] as $kind => [$label, $path, $hint])
                <div wire:key="shop-upload-{{ $kind }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <label class="block text-sm font-bold">{{ $label }}<input type="file" wire:model="{{ $kind }}" accept="image/png,image/jpeg,image/webp" class="mt-2 block w-full text-sm"></label>
                    <p class="mt-2 text-xs text-zinc-500">{{ $hint }}</p>
                    @if ($this->{$kind} && $this->{$kind}->isPreviewable())<img src="{{ $this->{$kind}->temporaryUrl() }}" alt="معاينة {{ $label }}" class="mt-3 h-28 w-full rounded-lg object-contain">
                    @elseif($path)<img src="{{ Storage::disk('public')->url($path) }}" alt="{{ $label }}" class="mt-3 h-28 w-full rounded-lg object-contain">@endif
                    @if($path)<button type="button" wire:click="removeImage('{{ $kind }}')" class="mt-2 text-xs text-rose-600">إزالة الصورة الحالية</button>@endif
                    <flux:error name="{{ $kind }}" />
                </div>
            @endforeach
        </div>
        <flux:input wire:model="tagline" label="وصف قصير تحت اسم المحل" maxlength="100" />
        <flux:input wire:model="heroTitle" label="عنوان البانر" maxlength="100" />
        <flux:textarea wire:model="heroText" label="نص البانر" rows="2" maxlength="250" />
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveAppearance,logo,banner">حفظ هوية المتجر</flux:button>
    </form>
</section>
