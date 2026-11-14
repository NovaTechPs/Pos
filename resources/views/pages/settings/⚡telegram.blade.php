<?php

use App\Models\TelegramLink;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A staff member links their own Telegram to the WaslaPOS bot and picks which store notifications
 * they receive (only those their permissions allow).
 */
new #[Title('Telegram')] class extends Component
{
    /** @var array<string, bool> */
    public array $notifications = [];

    public ?string $startUrl = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->tenant_id, 404);
        $link = $this->link();
        foreach ($this->available() as $key => $label) {
            $this->notifications[$key] = $link ? $link->wants($key) : true;
        }
    }

    /** @return array<string, string> */
    public function available(): array
    {
        $user = auth()->user();

        return collect(TelegramLink::STAFF_NOTIFICATIONS)
            ->filter(fn (array $notification): bool => $user->hasPermission($notification['permission']))
            ->map(fn (array $notification): string => $notification['label'])->all();
    }

    private function link(): ?TelegramLink
    {
        $user = auth()->user();

        return TelegramLink::query()->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->first();
    }

    public function createLink(): void
    {
        $user = auth()->user();
        $link = TelegramLink::issueFor((int) $user->tenant_id, userId: $user->id);
        $link->update(['notifications' => $this->chosen()]);
        $this->startUrl = $link->startUrl();
        if (! $this->startUrl) {
            $this->addError('startUrl', 'بوت تيليجرام غير مفعّل على الخادم بعد.');
        }
    }

    public function saveNotifications(): void
    {
        $this->link()?->update(['notifications' => $this->chosen()]);
        $this->dispatch('toast', type: 'success', message: 'تم حفظ الإشعارات.');
    }

    public function unlink(): void
    {
        $this->link()?->delete();
        $this->startUrl = null;
        $this->dispatch('toast', type: 'success', message: 'تم فك ربط تيليجرام.');
    }

    /** @return array<string, bool> */
    private function chosen(): array
    {
        $allowed = array_keys($this->available());

        return collect(TelegramLink::STAFF_NOTIFICATIONS)->keys()
            ->mapWithKeys(fn (string $key): array => [$key => in_array($key, $allowed, true) && (bool) ($this->notifications[$key] ?? false)])->all();
    }

    public function render(): mixed
    {
        return $this->view(['link' => $this->link(), 'botReady' => filled(config('services.telegram.bot_token')) && filled(config('services.telegram.bot_username'))]);
    }
}; ?>

<section class="w-full">
    @include('pages.settings.partials.settings-heading')

    <x-pages::settings.layout heading="تيليجرام / Telegram" subheading="استلم إشعارات المتجر على تيليجرام">
        <div class="space-y-5" data-telegram-settings>
            @unless ($botReady)
                <p class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">بوت تيليجرام غير مفعّل على الخادم بعد.</p>
            @endunless

            @if ($link?->isLinked())
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-200">
                    ✅ مربوط بحساب «{{ $link->chat_name ?: 'تيليجرام' }}» منذ {{ $link->linked_at?->format('Y-m-d') }}.
                </div>
            @endif

            <div class="space-y-2">
                <flux:heading size="sm">الإشعارات</flux:heading>
                @forelse ($this->available() as $key => $label)
                    <flux:checkbox wire:model="notifications.{{ $key }}" :label="$label" />
                @empty
                    <p class="text-sm text-zinc-500">لا توجد إشعارات متاحة لصلاحياتك.</p>
                @endforelse
            </div>

            @if ($startUrl)
                <div class="space-y-2 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700" data-telegram-start-url>
                    <p class="text-sm">افتح الرابط واضغط <b>Start</b> في البوت لإكمال الربط:</p>
                    <a href="{{ $startUrl }}" target="_blank" rel="noopener" class="inline-flex rounded-lg bg-sky-600 px-4 py-2 text-sm font-bold text-white hover:bg-sky-700">✈️ فتح البوت في تيليجرام</a>
                </div>
            @endif
            @error('startUrl') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror

            <div class="flex flex-wrap gap-2">
                @if ($link?->isLinked())
                    <flux:button variant="primary" wire:click="saveNotifications">حفظ الإشعارات</flux:button>
                    <flux:button variant="danger" wire:click="unlink" wire:confirm="فك ربط تيليجرام؟">فك الربط</flux:button>
                @else
                    <flux:button variant="primary" wire:click="createLink" :disabled="! $botReady">ربط تيليجرام</flux:button>
                @endif
            </div>
        </div>
    </x-pages::settings.layout>
</section>
