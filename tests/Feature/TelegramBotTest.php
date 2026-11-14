<?php

use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\CustomerOrder;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\TelegramLink;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-token', 'services.telegram.bot_username' => 'WaslaPosBot',
        'services.telegram.webhook_secret' => 'hook-secret', 'services.telegram.chat_id' => null]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $this->tenant = Tenant::query()->create(['name' => 'متجر النور', 'is_active' => true]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الرئيسي', 'domain' => 'alnoor']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'أبو أحمد', 'type' => 'customer', 'is_active' => true,
        'phone' => '0599000111', 'current_balance' => 150]);
    $this->webhook = fn (string $text, string $chatId = '777') => $this->postJson(route('telegram.webhook'),
        ['message' => ['chat' => ['id' => $chatId, 'type' => 'private'], 'from' => ['first_name' => 'Ahmad'], 'text' => $text]],
        ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret']);
    $this->linkCustomer = fn (string $chatId = '777') => TelegramLink::query()->create(['tenant_id' => $this->tenant->id,
        'party_id' => $this->customer->id, 'chat_id' => $chatId, 'linked_at' => now()]);
    $this->sentTexts = fn () => Http::recorded()->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))
        ->map(fn (Request $request): string => $request['chat_id'].': '.$request['text'])->values()->all();
});

test('the webhook refuses requests without the secret', function (): void {
    $this->postJson(route('telegram.webhook'), ['message' => ['chat' => ['id' => 1], 'text' => '/start']])->assertForbidden();
    $this->postJson(route('telegram.webhook'), [], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
});

test('a customer links their chat with a one-time start link', function (): void {
    $link = TelegramLink::issueFor($this->tenant->id, partyId: $this->customer->id);
    expect($link->startUrl())->toBe('https://t.me/WaslaPosBot?start='.$link->link_token);

    ($this->webhook)('/start '.$link->link_token)->assertOk();

    expect($link->fresh())->chat_id->toBe('777')->chat_name->toBe('Ahmad')->link_token->toBeNull();
    expect(($this->sentTexts)()[0])->toContain('تم ربط حسابك لدى متجر النور');

    ($this->webhook)('/start '.$link->getOriginal('link_token'), '888');
    expect(TelegramLink::query()->where('chat_id', '888')->exists())->toBeFalse()
        ->and(($this->sentTexts)()[1])->toContain('غير صالح');
});

test('an expired start link does not link the chat', function (): void {
    $link = TelegramLink::issueFor($this->tenant->id, partyId: $this->customer->id);
    $link->update(['link_token_expires_at' => now()->subMinute()]);

    ($this->webhook)('/start '.$link->link_token);

    expect($link->fresh()->chat_id)->toBeNull();
});

test('a linked customer asks for the balance and can stop the messages', function (): void {
    ($this->linkCustomer)();

    ($this->webhook)('/balance');
    expect(($this->sentTexts)()[0])->toContain('متجر النور: مستحق عليك 150.00');

    ($this->webhook)('/stop');
    expect(TelegramLink::query()->linked()->count())->toBe(0);
});

test('a completed invoice for a linked customer is sent as a PDF after the response', function (): void {
    ($this->linkCustomer)();
    $order = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        'customer_name' => 'أبو أحمد', 'invoice_number' => 'INV-55', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 40, 'total' => 40, 'paid_amount' => 0]);
    $order->update(['notes' => 'edited']);
    Http::assertNothingSent();

    app()->terminate();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendDocument')
        && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'caption' && str_contains($part['contents'], 'فاتورة رقم INV-55')));
});

test('invoices of customers without Telegram and unfinished POS sales send nothing', function (): void {
    ($this->linkCustomer)();
    $unlinked = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'زبون آخر', 'type' => 'customer', 'is_active' => true]);
    Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $unlinked->id,
        'invoice_number' => 'INV-56', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 40, 'total' => 40, 'paid_amount' => 0]);
    Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        'invoice_number' => 'POS-1', 'type' => 'pos', 'status' => 'pending', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 0]);

    app()->terminate();

    Http::assertNothingSent();
});

test('a collection receipt reaches the customer, a payment taken with an invoice does not', function (): void {
    ($this->linkCustomer)();
    $base = ['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $this->customer->id, 'type' => 'receipt',
        'payment_date' => now(), 'payment_method' => 'cash'];
    Payment::query()->create($base + ['voucher_number' => 'RCV-9', 'amount' => 50]);
    $order = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'X-1',
        'type' => 'pos', 'status' => 'pending', 'subtotal' => 5, 'total' => 5, 'paid_amount' => 5]);
    Payment::query()->create($base + ['voucher_number' => 'RCV-10', 'amount' => 5, 'order_id' => $order->id]);

    app()->terminate();

    expect(($this->sentTexts)())->toHaveCount(1)
        ->and(($this->sentTexts)()[0])->toContain('تم استلام دفعة منك بقيمة 50.00')->toContain('RCV-9');
});

test('a closed shift reaches staff who may see settlements and asked for it', function (): void {
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'chat_id' => '1001', 'linked_at' => now(),
        'notifications' => ['shift_closed' => true]]);
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'كاشير']);
    $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'pos.view'], ['display_name' => 'pos', 'group' => 'test']));
    $cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id]);
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $cashier->id, 'chat_id' => '1002', 'linked_at' => now(),
        'notifications' => ['shift_closed' => true]]);
    $shift = Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $cashier->id,
        'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);

    $shift->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $cashier->id, 'total_sales' => 300,
        'expected_cash' => 300, 'actual_cash' => 290, 'difference' => -10]);
    app()->terminate();

    expect(($this->sentTexts)())->toHaveCount(1)
        ->and(($this->sentTexts)()[0])->toStartWith('1001: ')->toContain('عجز ⚠️: 10.00');
});

test('staff who asked for online orders hear about a new one with its total', function (): void {
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'chat_id' => '1001', 'linked_at' => now(),
        'notifications' => ['online_orders' => true]]);
    $order = CustomerOrder::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'reference' => 'WEB-1',
        'tracking_token' => str_repeat('a', 48), 'customer_name' => 'زائر', 'customer_phone' => '0591', 'price_list' => 'retail', 'status' => 'new', 'total' => 0]);
    $order->update(['total' => 75]);

    app()->terminate();

    expect(($this->sentTexts)()[0])->toContain('طلب جديد')->toContain('WEB-1')->toContain('75.00');
});

test('the daily summary goes to staff who asked for it', function (): void {
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'chat_id' => '1001', 'linked_at' => now(),
        'notifications' => ['daily_summary' => true]]);
    Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'D-1',
        'type' => 'pos', 'status' => 'completed', 'subtotal' => 120, 'total' => 120, 'paid_amount' => 120]);

    $this->artisan('telegram:daily-summary')->expectsOutputToContain('sent to 1 chat(s)')->assertSuccessful();

    expect(($this->sentTexts)()[0])->toContain('ملخص اليوم')->toContain('المبيعات: 120.00 (1 فاتورة)');
});

test('staff create a customer link from the customers page', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);

    Livewire::test('pages::tenant.customers.telegram-link')->call('openFor', $this->customer->id)->call('createLink')
        ->assertSet('startUrl', fn (?string $url): bool => str_starts_with((string) $url, 'https://t.me/WaslaPosBot?start='));

    expect(TelegramLink::query()->where('party_id', $this->customer->id)->whereNotNull('link_token')->exists())->toBeTrue();
});

test('a staff member links their own Telegram and only gets notifications their role allows', function (): void {
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'محاسب']);
    $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'reports.daily_settlement'], ['display_name' => 'x', 'group' => 'test']));
    $accountant = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id]);
    $this->actingAs($accountant)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);

    $this->get(route('telegram.edit'))->assertOk()->assertSee('ربط تيليجرام');
    Livewire::test('pages::settings.telegram')->set('notifications.daily_summary', true)->call('createLink')
        ->assertSet('startUrl', fn (?string $url): bool => str_starts_with((string) $url, 'https://t.me/WaslaPosBot?start='));

    expect(TelegramLink::query()->where('user_id', $accountant->id)->value('notifications'))
        ->toBe(['daily_summary' => false, 'shift_closed' => true, 'online_orders' => false]);
});

test('a shop customer opens the bot from their account page', function (): void {
    TenantSetting::setBool($this->tenant->id, 'online_shop_enabled', true);
    $account = CustomerAccount::query()->create(['tenant_id' => $this->tenant->id, 'party_id' => $this->customer->id, 'phone' => '0599000111', 'password' => 'secret-pass']);
    Auth::guard('customer')->setUser($account);

    Livewire::test('pages::shop.account', ['shop' => 'alnoor'])->assertSee('استلم فواتيرك على تيليجرام')
        ->call('linkTelegram')->assertRedirectContains('https://t.me/WaslaPosBot?start=');
});

test('nothing is sent while the bot is not configured', function (): void {
    config(['services.telegram.bot_token' => null]);
    ($this->linkCustomer)();
    Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        'invoice_number' => 'INV-57', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 40, 'total' => 40, 'paid_amount' => 0]);

    app()->terminate();

    Http::assertNothingSent();
});
