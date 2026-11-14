<?php

use App\Models\CustomerAccount;
use App\Models\Party;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Staff open, change or close a customer's sign-in to the online shop: phone, password,
 * retail or wholesale prices, and whether it may sign in.
 */
new class extends Component
{
    public bool $open = false;

    public ?int $partyId = null;

    public string $partyName = '';

    public string $phone = '';

    public string $password = '';

    public string $priceList = 'retail';

    public bool $isActive = true;

    public bool $exists = false;

    private function tenantId(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    #[On('open-shop-account')]
    public function openFor(int $partyId): void
    {
        $this->authorize('customers.edit');
        $party = Party::query()->where('tenant_id', $this->tenantId())->whereIn('type', ['customer', 'both'])->findOrFail($partyId);
        $account = CustomerAccount::query()->where('tenant_id', $this->tenantId())->where('party_id', $party->id)->first();

        $this->resetValidation();
        $this->partyId = $party->id;
        $this->partyName = $party->name;
        $this->exists = (bool) $account;
        $this->phone = (string) ($account->phone ?? CustomerAccount::normalizePhone((string) $party->phone));
        $this->password = '';
        $this->priceList = (string) ($account->price_list ?? 'retail');
        $this->isActive = (bool) ($account->is_active ?? true);
        $this->open = true;
    }

    public function save(): void
    {
        $this->authorize('customers.edit');
        $tenantId = $this->tenantId();
        $party = Party::query()->where('tenant_id', $tenantId)->whereIn('type', ['customer', 'both'])->findOrFail($this->partyId);
        $account = CustomerAccount::query()->where('tenant_id', $tenantId)->where('party_id', $party->id)->first();
        $this->phone = CustomerAccount::normalizePhone($this->phone);

        $this->validate([
            'phone' => ['required', 'string', 'min:7', 'max:30', Rule::unique('customer_accounts', 'phone')->where('tenant_id', $tenantId)->ignore($account?->id)],
            'password' => [$account ? 'nullable' : 'required', 'string', Password::min(8)],
            'priceList' => ['required', Rule::in(array_keys(CustomerAccount::PRICE_LISTS))],
            'isActive' => ['boolean'],
        ], [], ['phone' => 'رقم الهاتف', 'password' => 'كلمة المرور', 'priceList' => 'الأسعار']);

        $attributes = ['phone' => $this->phone, 'price_list' => $this->priceList, 'is_active' => $this->isActive];
        if ($this->password !== '') {
            $attributes['password'] = $this->password;
        }
        $account
            ? $account->update($attributes)
            : CustomerAccount::query()->create($attributes + ['tenant_id' => $tenantId, 'party_id' => $party->id]);

        $this->open = false;
        $this->password = '';
        $this->dispatch('toast', type: 'success', message: 'تم حفظ حساب المتجر للزبون '.$party->name.'.');
    }
};
?>

<div>
    <flux:modal wire:model="open" class="w-full max-w-md" data-shop-account-modal>
        <form wire:submit="save" class="space-y-4">
            <div>
                <flux:heading size="lg">حساب المتجر الإلكتروني</flux:heading>
                <flux:subheading>{{ $partyName }} — {{ $exists ? 'حساب موجود' : 'إنشاء حساب جديد' }}</flux:subheading>
            </div>

            <flux:input wire:model="phone" label="رقم الهاتف (اسم الدخول)" dir="ltr" />
            <flux:input type="password" wire:model="password" :label="$exists ? 'كلمة مرور جديدة (اتركها فارغة للإبقاء على الحالية)' : 'كلمة المرور'" autocomplete="new-password" />

            <flux:select wire:model="priceList" label="الأسعار التي يراها">
                @foreach (\App\Models\CustomerAccount::PRICE_LISTS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            <flux:checkbox wire:model="isActive" label="يستطيع تسجيل الدخول" />

            <p class="text-xs text-zinc-500">يرى الزبون بعد الدخول طلباته وفواتيره وكشف حسابه فقط. أرسل له رقم الهاتف وكلمة المرور ورابط متجر الفرع.</p>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" x-on:click="$wire.open = false">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">حفظ</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
