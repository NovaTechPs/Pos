<?php

use App\Models\TelegramLink;
use App\Models\TenantSetting;
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

    /** Store owner only: send linked customers their account statement on the first of each month. */
    public bool $monthlyStatements = true;

    /** Store owner only: a sale whose discount reaches this percent warns the owner (0 = off). */
    public int $discountAlertPercent = 10;

    /** Store owner only: remind customers who paid nothing for this many days (0 = off). */
    public int $debtReminderDays = 0;

    public function mount(): void
    {
        abort_unless(auth()->user()->tenant_id, 404);
        $tenantId = (int) auth()->user()->tenant_id;
        $this->monthlyStatements = TenantSetting::getBool($tenantId, 'telegram_monthly_statements', true);
        $this->discountAlertPercent = (int) TenantSetting::get($tenantId, 'telegram_discount_alert_percent', 10);
        $this->debtReminderDays = (int) TenantSetting::get($tenantId, 'telegram_debt_reminder_days', 0);
        $link = $this->link();
        foreach ($this->available() as $key => $label) {
            $this->notifications[$key] = $link ? $link->wants($key) : TelegramLink::STAFF_NOTIFICATIONS[$key]['default'];
        }
    }

    /** @return array<string, string> */
    public function available(): array
    {
        $user = auth()->user();

        return collect(TelegramLink::STAFF_NOTIFICATIONS)
            ->filter(fn (array $notification, string $key): bool => TelegramLink::allows($user, $key))
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

    public function updatedMonthlyStatements(bool $value): void
    {
        abort_unless(auth()->user()->isTenantOwner(), 403);
        TenantSetting::setBool((int) auth()->user()->tenant_id, 'telegram_monthly_statements', $value);
        $this->dispatch('toast', type: 'success', message: 'تم حفظ الإعداد.');
    }

    public function saveStoreSettings(): void
    {
        abort_unless(auth()->user()->isTenantOwner(), 403);
        $this->validate([
            'discountAlertPercent' => ['required', 'integer', 'min:0', 'max:100'],
            'debtReminderDays' => ['required', 'integer', 'in:0,7,15,30'],
        ]);
        $tenantId = (int) auth()->user()->tenant_id;
        TenantSetting::set($tenantId, 'telegram_discount_alert_percent', (string) $this->discountAlertPercent);
        TenantSetting::set($tenantId, 'telegram_debt_reminder_days', (string) $this->debtReminderDays);
        $this->dispatch('toast', type: 'success', message: 'تم حفظ الإعدادات.');
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

            @if (auth()->user()->isTenantOwner())
                <div class="space-y-2 border-t border-zinc-200 pt-4 dark:border-zinc-700" data-telegram-customer-settings>
                    <flux:heading size="sm">للزبائن المربوطين</flux:heading>
                    <flux:checkbox wire:model.live="monthlyStatements" label="إرسال كشف الحساب الشهري PDF أول كل شهر" />
                    <p class="text-xs text-zinc-500">الفواتير والمردودات وسندات القبض تصل الزبون PDF تلقائياً، ويُطبع على فاتورته رمز QR ليربط تيليجرام بنفسه.</p>
                    <flux:select wire:model="debtReminderDays" label="تذكير الزبون بدينه إذا لم يدفع منذ">
                        <flux:select.option value="0">لا ترسل تذكيراً</flux:select.option>
                        <flux:select.option value="7">7 أيام</flux:select.option>
                        <flux:select.option value="15">15 يوماً</flux:select.option>
                        <flux:select.option value="30">30 يوماً</flux:select.option>
                    </flux:select>
                </div>

                <div class="space-y-2 border-t border-zinc-200 pt-4 dark:border-zinc-700" data-telegram-store-settings>
                    <flux:heading size="sm">تنبيهات المراجعة</flux:heading>
                    <flux:input type="number" min="0" max="100" wire:model="discountAlertPercent" label="نبّهني عن الفاتورة إذا وصل خصمها إلى (%)" description="0 لإيقاف تنبيه الخصم؛ البيع بأقل من التكلفة يُنبَّه عنه دائماً." />
                    <flux:button size="sm" wire:click="saveStoreSettings">حفظ إعدادات المتجر</flux:button>
                </div>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
