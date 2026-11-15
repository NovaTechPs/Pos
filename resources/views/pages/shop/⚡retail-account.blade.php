<?php
use App\Models\CustomerOrder;
use App\Models\StoreCustomer;
use App\Services\OnlineShop;
use App\Services\StoreCustomerAddresses;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
new class extends Component
{
    use WithPagination;
    #[Locked]
    public string $shop = '';
    #[Locked]
    public ?int $editingAddressId = null;
    /** @var array{label: string, recipient_name: string, phone: string, city: string, address_line: string, is_default: bool} */
    public array $addressForm = ['label' => '', 'recipient_name' => '', 'phone' => '', 'city' => '', 'address_line' => '', 'is_default' => false];
    public string $name = '';
    public string $currentPassword = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $shop): void
    {
        $this->shop = $shop;
        $customer = $this->customer();
        $this->name = $customer->name;
        $this->newAddress();
    }
    private function customer(): StoreCustomer
    {
        $service = app(OnlineShop::class);
        $customer = $service->storeCustomer($service->branch($this->shop));
        abort_unless($customer, 403);
        return $customer;
    }
    public function newAddress(): void
    {
        $customer = $this->customer();
        $this->editingAddressId = null;
        $this->addressForm = ['label' => '', 'recipient_name' => $customer->name, 'phone' => $customer->phone, 'city' => '', 'address_line' => '', 'is_default' => false];
        $this->resetValidation();
    }
    public function editAddress(int $id): void
    {
        $address = $this->customer()->addresses()->findOrFail($id);
        $this->editingAddressId = $address->id;
        $this->addressForm = $address->only(array_keys($this->addressForm));
        $this->resetValidation();
    }
    public function saveAddress(): void
    {
        $this->validate(['addressForm' => ['required', 'array:label,recipient_name,phone,city,address_line,is_default'],
            'addressForm.label' => ['required', 'string', 'max:50'], 'addressForm.recipient_name' => ['required', 'string', 'max:255'],
            'addressForm.phone' => ['required', 'string', 'max:30'], 'addressForm.city' => ['required', 'string', 'max:100'],
            'addressForm.address_line' => ['required', 'string', 'max:350'], 'addressForm.is_default' => ['required', 'boolean']]);
        app(StoreCustomerAddresses::class)->save($this->customer(), $this->addressForm, $this->editingAddressId);
        $this->newAddress();
        session()->flash('saved', 'تم حفظ العنوان.');
    }
    public function deleteAddress(int $id): void
    {
        app(StoreCustomerAddresses::class)->delete($this->customer(), $id);
        $this->newAddress();
        session()->flash('saved', 'تم حذف العنوان.');
    }
    public function saveProfile(): void
    {
        $customer = $this->customer();
        $this->name = trim($this->name);
        $customer->update($this->validate(['name' => ['required', 'string', 'max:255']]));
        session()->flash('saved', 'تم حفظ الاسم.');
    }
    public function changePassword(): void
    {
        $customer = $this->customer();
        $this->validate(['currentPassword' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'confirmed', 'max:255', Password::min(8)]]);
        if (! Hash::check($this->currentPassword, $customer->password)) {
            $this->addError('currentPassword', 'كلمة المرور الحالية غير صحيحة.');
            return;
        }
        $customer->forceFill(['password' => $this->password, 'remember_token' => str()->random(60)])->save();
        $this->reset('currentPassword', 'password', 'password_confirmation');
        session()->regenerate();
        session()->flash('saved', 'تم تغيير كلمة المرور.');
    }
    public function render(): mixed
    {
        $customer = $this->customer();
        return $this->view(['customer' => $customer,
            'addresses' => $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get(),
            'orders' => CustomerOrder::query()->where('tenant_id', $customer->tenant_id)->where('store_customer_id', $customer->id)
                ->latest()->paginate(10),
        ])->layout('layouts::shop', ['branch' => app(OnlineShop::class)->branch($this->shop), 'title' => 'حسابي وطلباتي']);
    }
};
?>
<div class="space-y-5" data-retail-account>
    <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <h1 class="text-xl font-black">أهلاً {{ $customer->name }}</h1>
        <p class="mt-1 text-sm text-slate-500">حسابك وعناوينك وطلباتك في هذا المتجر.</p>
        <p class="mt-2 text-sm text-slate-500" dir="ltr">{{ $customer->phone }}</p>
    </div>
    @if (session('saved'))<p role="status" class="rounded-xl bg-teal-50 p-3 text-teal-800">{{ session('saved') }}</p>@endif
    @if ($errors->any())<div role="alert" class="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <div class="grid gap-5 lg:grid-cols-[1fr_22rem]">
        <section class="space-y-4">
            <h2 class="text-lg font-black">طلباتي</h2>
            @forelse ($orders as $order)
                <article wire:key="order-{{ $order->id }}" class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-mono font-bold" dir="ltr">{{ $order->reference }}</span>
                        <span class="rounded-full bg-teal-50 px-3 py-1 text-xs font-bold text-teal-700">{{ $order->statusLabel() }}</span>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">{{ $order->created_at->format('Y-m-d H:i') }}</p>
                    <div class="mt-3 flex items-center justify-between gap-2">
                        <span class="font-mono font-black">{{ number_format((float) $order->total, 2) }}</span>
                        <a href="{{ route('shop.track', ['shop' => $shop, 'token' => $order->tracking_token]) }}" wire:navigate class="font-bold text-teal-700">تفاصيل الطلب</a>
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 p-8 text-center">
                    <p class="text-slate-500">لا توجد طلبات بعد.</p>
                    <a href="{{ route('shop.home', ['shop' => $shop]) }}" wire:navigate class="mt-3 inline-block font-bold text-teal-700">ابدأ التسوق</a>
                </div>
            @endforelse
            {{ $orders->links() }}
            <div class="flex items-center justify-between gap-3"><h2 class="text-lg font-black">عناويني</h2><button type="button" wire:click="newAddress" class="text-sm font-bold text-teal-700">إضافة عنوان</button></div>
            @forelse ($addresses as $address)
                <article wire:key="address-{{ $address->id }}" class="rounded-2xl border border-slate-200 bg-white p-4">
                    <h3 class="font-bold">{{ $address->label }} @if ($address->is_default)<span class="text-xs text-teal-700">· الافتراضي</span>@endif</h3>
                    <p class="mt-1 text-sm">{{ $address->recipient_name }} · {{ $address->phone }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $address->deliveryAddress() }}</p>
                    <div class="mt-3 flex gap-4 text-sm"><button type="button" wire:click="editAddress({{ $address->id }})" class="font-bold text-teal-700">تعديل</button><button type="button" wire:click="deleteAddress({{ $address->id }})" wire:confirm="حذف هذا العنوان؟" class="text-rose-600">حذف</button></div>
                </article>
            @empty
                <p class="text-sm text-slate-500">أضف عنوانك لتستخدمه عند إتمام الطلب.</p>
            @endforelse
        </section>
        <aside class="space-y-4">
            <form wire:submit="saveAddress" class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4">
                <h2 class="font-black">{{ $editingAddressId ? 'تعديل العنوان' : 'عنوان جديد' }}</h2>
                @foreach (['label' => 'اسم العنوان (المنزل / العمل)', 'recipient_name' => 'اسم المستلم', 'phone' => 'رقم الهاتف', 'city' => 'المدينة', 'address_line' => 'الشارع وتفاصيل العنوان'] as $field => $label)
                    <label wire:key="address-field-{{ $field }}" class="block text-sm"><span>{{ $label }}</span><input wire:model="addressForm.{{ $field }}" type="{{ $field === 'phone' ? 'tel' : 'text' }}" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label>
                @endforeach
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="addressForm.is_default">العنوان الافتراضي</label>
                <button wire:loading.attr="disabled" class="w-full rounded-xl bg-teal-600 py-2 font-bold text-white">حفظ العنوان</button>
            </form>
            <form wire:submit="saveProfile" class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4">
                <h2 class="font-black">بياناتي</h2>
                <input wire:model="name" aria-label="الاسم" autocomplete="name" class="w-full rounded-lg border border-slate-200 px-3 py-2">
                <button wire:loading.attr="disabled" class="w-full rounded-xl border border-slate-200 py-2 font-bold">حفظ الاسم</button>
            </form>
            <form wire:submit="changePassword" class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4">
                <h2 class="font-black">تغيير كلمة المرور</h2>
                <input type="password" wire:model="currentPassword" autocomplete="current-password" aria-label="كلمة المرور الحالية" placeholder="كلمة المرور الحالية" class="w-full rounded-lg border border-slate-200 px-3 py-2">
                <input type="password" wire:model="password" autocomplete="new-password" aria-label="كلمة المرور الجديدة" placeholder="كلمة المرور الجديدة" class="w-full rounded-lg border border-slate-200 px-3 py-2">
                <input type="password" wire:model="password_confirmation" autocomplete="new-password" aria-label="تأكيد كلمة المرور" placeholder="تأكيد كلمة المرور" class="w-full rounded-lg border border-slate-200 px-3 py-2">
                <button wire:loading.attr="disabled" class="w-full rounded-xl border border-slate-200 py-2 font-bold">تحديث كلمة المرور</button>
            </form>
        </aside>
    </div>
</div>
