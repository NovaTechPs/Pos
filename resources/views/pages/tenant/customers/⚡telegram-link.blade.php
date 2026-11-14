<?php

use App\Models\Party;
use App\Models\TelegramLink;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Staff create a one-time link a customer opens to receive their invoices, receipts and balance on
 * Telegram, or stop the messages for that customer.
 */
new class extends Component
{
    public bool $open = false;

    public ?int $partyId = null;

    public string $partyName = '';

    public string $partyPhone = '';

    public ?string $linkedName = null;

    public ?string $linkedAt = null;

    public ?string $startUrl = null;

    private function tenantId(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    private function party(): Party
    {
        return Party::query()->where('tenant_id', $this->tenantId())->whereIn('type', ['customer', 'both'])->findOrFail($this->partyId);
    }

    #[On('open-telegram-link')]
    public function openFor(int $partyId): void
    {
        $this->authorize('customers.edit');
        $this->partyId = $partyId;
        $party = $this->party();
        $this->partyName = $party->name;
        $this->partyPhone = preg_replace('/\D+/', '', (string) $party->phone) ?? '';
        $this->startUrl = null;
        $this->loadStatus();
        $this->open = true;
    }

    public function createLink(): void
    {
        $this->authorize('customers.edit');
        $party = $this->party();
        $this->startUrl = TelegramLink::issueFor($this->tenantId(), partyId: $party->id)->startUrl();
        if (! $this->startUrl) {
            $this->addError('startUrl', 'بوت تيليجرام غير مفعّل على الخادم بعد.');
        }
    }

    public function unlink(): void
    {
        $this->authorize('customers.edit');
        TelegramLink::query()->where('tenant_id', $this->tenantId())->where('party_id', $this->party()->id)->delete();
        $this->startUrl = null;
        $this->loadStatus();
        $this->dispatch('toast', type: 'success', message: 'تم إيقاف رسائل تيليجرام لهذا الزبون.');
    }

    private function loadStatus(): void
    {
        $link = TelegramLink::query()->linked()->where('tenant_id', $this->tenantId())->where('party_id', $this->partyId)->first();
        $this->linkedName = $link ? ($link->chat_name ?: 'مربوط') : null;
        $this->linkedAt = $link?->linked_at?->format('Y-m-d');
    }
};
?>

<div>
    <flux:modal wire:model="open" class="w-full max-w-md" data-telegram-link-modal>
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">تيليجرام الزبون</flux:heading>
                <flux:subheading>{{ $partyName }}</flux:subheading>
            </div>

            @if ($linkedName)
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-200">
                    ✅ مربوط بحساب «{{ $linkedName }}» منذ {{ $linkedAt }}. تصله فواتيره وسندات القبض تلقائياً.
                </div>
            @else
                <p class="text-sm text-zinc-600 dark:text-zinc-300">بعد الربط تصل الزبونَ كل فاتورة بصيغة PDF وكل سند قبض ورصيده، ويستطيع طلب رصيده وآخر فواتيره من البوت.</p>
            @endif

            @if ($startUrl)
                <div class="space-y-2 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700" data-telegram-start-url>
                    <p class="text-xs text-zinc-500">أرسل هذا الرابط للزبون (صالح لمدة 7 أيام ولمرة واحدة):</p>
                    <flux:input readonly :value="$startUrl" dir="ltr" copyable />
                    <a href="https://wa.me/{{ $partyPhone }}?text={{ rawurlencode('اضغط الرابط لاستلام فواتيرك وكشف حسابك على تيليجرام: '.$startUrl) }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 text-sm font-bold text-emerald-700">إرسال الرابط عبر واتساب ↗</a>
                </div>
            @endif
            @error('startUrl') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror

            <div class="flex flex-wrap justify-end gap-2">
                @if ($linkedName)
                    <flux:button variant="danger" wire:click="unlink" wire:confirm="إيقاف رسائل تيليجرام لهذا الزبون؟">فك الربط</flux:button>
                @endif
                <flux:button variant="primary" wire:click="createLink">{{ $linkedName ? 'رابط لجهاز آخر' : 'إنشاء رابط الربط' }}</flux:button>
                <flux:button variant="ghost" x-on:click="$wire.open = false">إغلاق</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
