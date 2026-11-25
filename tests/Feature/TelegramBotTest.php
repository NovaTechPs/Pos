<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\CustomerAccount;
use App\Models\CustomerOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Shift;
use App\Models\TelegramLink;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\TelegramQr;
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

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendDocument')
        && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'document' && str_ends_with((string) ($part['filename'] ?? ''), '.pdf'))
        && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'caption'
            && str_contains($part['contents'], 'تم استلام دفعة منك بقيمة 50.00') && str_contains($part['contents'], 'RCV-9')));
});

test('a sales return of a linked customer is sent to them as a PDF', function (): void {
    ($this->linkCustomer)();
    Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        'customer_name' => 'أبو أحمد', 'invoice_number' => 'RET-3', 'type' => 'return', 'status' => 'completed', 'subtotal' => 20, 'total' => 20, 'paid_amount' => 0]);

    app()->terminate();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendDocument')
        && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'caption' && str_contains($part['contents'], 'مردود مبيعات رقم RET-3')));
});

test('the owner gets every invoice, voucher and purchase they chose, and nothing they did not', function (): void {
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'chat_id' => '1001', 'linked_at' => now(),
        'notifications' => ['sales_invoices' => true, 'receipts' => true, 'payments' => true, 'purchases' => true, 'sales_returns' => false]]);
    Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        'customer_name' => 'أبو أحمد', 'invoice_number' => 'INV-70', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 100, 'total' => 100, 'paid_amount' => 60]);
    Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'RET-70',
        'type' => 'return', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 0]);
    Payment::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $this->customer->id,
        'type' => 'receipt', 'payment_date' => now(), 'payment_method' => 'cash', 'voucher_number' => 'RCV-70', 'amount' => 40]);
    Payment::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'type' => 'payment', 'payment_date' => now(),
        'payment_method' => 'cash', 'voucher_number' => 'PAY-70', 'amount' => 25, 'expense_category' => 'كهرباء']);
    Purchase::query()->withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'reference_number' => 'PUR-70', 'total' => 500, 'paid_amount' => 500]);

    app()->terminate();

    $ownerTexts = collect(($this->sentTexts)())->filter(fn (string $text): bool => str_starts_with($text, '1001: '))->values();
    expect($ownerTexts)->toHaveCount(4)
        ->and($ownerTexts->implode("\n"))
        ->toContain('INV-70')->toContain('المتبقي آجل: 40.00')
        ->toContain('RCV-70')->toContain('PAY-70')->toContain('مصروف: كهرباء')
        ->toContain('PUR-70')->toContain('500.00')
        ->not->toContain('RET-70');
});

test('a staff member cannot receive the owner-only document notifications', function (): void {
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'محاسب']);
    $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'sales_invoices.view'], ['display_name' => 'x', 'group' => 'test']));
    $accountant = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id]);
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $accountant->id, 'chat_id' => '2002', 'linked_at' => now(),
        'notifications' => ['sales_invoices' => true]]);
    Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'INV-71',
        'type' => 'pos', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10]);

    app()->terminate();

    Http::assertNothingSent();
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
        ->toEqual(['daily_summary' => false, 'shift_closed' => true, 'online_orders' => false, 'sales_invoices' => false,
            'sales_returns' => false, 'receipts' => false, 'payments' => false, 'purchases' => false, 'document_changes' => false,
            'shift_problems' => false, 'low_stock' => false, 'risky_sales' => false, 'weekly_report' => false, 'monthly_report' => false]);
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

test('the owner is warned with who did it when a saved invoice changes amount or a voucher is deleted', function (): void {
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'chat_id' => '1001', 'linked_at' => now(),
        'notifications' => ['document_changes' => true]]);
    $order = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'INV-80',
        'type' => 'pos', 'status' => 'completed', 'subtotal' => 100, 'total' => 100, 'paid_amount' => 100]);
    $voucher = Payment::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'type' => 'payment',
        'payment_date' => now(), 'payment_method' => 'cash', 'voucher_number' => 'PAY-80', 'amount' => 30]);
    app()->terminate();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    app()->forgetScopedInstances();

    $this->actingAs($this->owner);
    Order::query()->whereKey($order->id)->first()->update(['total' => 70, 'subtotal' => 70]);
    Payment::query()->whereKey($voucher->id)->first()->delete();
    app()->terminate();

    $texts = collect(($this->sentTexts)())->implode("\n");
    expect($texts)->toContain('تعديل فاتورة مبيعات')->toContain('INV-80')->toContain('المبلغ قبل: 100.00')->toContain('المبلغ بعد: 70.00')
        ->toContain('حذف سند صرف')->toContain('PAY-80')->toContain('بواسطة: '.$this->owner->name);
});

test('a document still being written in the same request does not warn the owner', function (): void {
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'chat_id' => '1001', 'linked_at' => now(),
        'notifications' => ['document_changes' => true]]);
    $purchase = Purchase::query()->withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'reference_number' => 'PUR-81', 'total' => 0, 'paid_amount' => 0]);
    $purchase->update(['total' => 250]);

    app()->terminate();

    Http::assertNothingSent();
});

test('an unlinked customer invoice prints a reusable Telegram QR, a linked customer gets none', function (): void {
    $url = TelegramLink::printableStartUrl($this->tenant->id, $this->customer->id);

    expect($url)->toStartWith('https://t.me/WaslaPosBot?start=')
        ->and(TelegramLink::printableStartUrl($this->tenant->id, $this->customer->id))->toBe($url)
        ->and(TelegramLink::query()->where('party_id', $this->customer->id)->first()->link_token_expires_at->gt(now()->addDays(50)))->toBeTrue()
        ->and(TelegramQr::svg($url))->toStartWith('<svg');

    $order = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        'invoice_number' => 'INV-90', 'type' => 'wholesale', 'status' => 'pending', 'subtotal' => 40, 'total' => 40, 'paid_amount' => 0]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id])
        ->get(route('tenant.sale-invoices.pdf', $order->id))->assertOk();

    TelegramLink::query()->where('party_id', $this->customer->id)->update(['chat_id' => '777', 'linked_at' => now()]);
    expect(TelegramLink::printableStartUrl($this->tenant->id, $this->customer->id))->toBeNull();
});

test('linked customers get last month statement as a PDF unless the owner turned it off', function (): void {
    ($this->linkCustomer)();
    $this->travelTo(now()->subMonthNoOverflow()->startOfMonth()->addDays(3));
    Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        'invoice_number' => 'INV-95', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 60, 'total' => 60, 'paid_amount' => 0]);
    $this->travelBack();

    $this->artisan('telegram:monthly-statements')->expectsOutputToContain('sent to 1 customer(s)')->assertSuccessful();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendDocument')
        && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'caption' && str_contains($part['contents'], 'كشف حسابك')));

    TenantSetting::setBool($this->tenant->id, 'telegram_monthly_statements', false);
    $this->artisan('telegram:monthly-statements')->expectsOutputToContain('sent to 0 customer(s)')->assertSuccessful();
});

test('only the owner sees the monthly statement switch', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);

    Livewire::test('pages::settings.telegram')->assertSee('كشف الحساب الشهري')->set('monthlyStatements', false);

    expect(TenantSetting::getBool($this->tenant->id, 'telegram_monthly_statements', true))->toBeFalse();
});

/** A product in the test branch with the given stock and reorder level. */
function telegramTestProduct(object $test, string $name, float $stock, float $alert = 5): Product
{
    $product = Product::query()->create(['tenant_id' => $test->tenant->id, 'name' => $name, 'cost_price' => 8]);
    BranchProduct::query()->create(['tenant_id' => $test->tenant->id, 'branch_id' => $test->branch->id, 'product_id' => $product->id,
        'retail_price' => 10, 'stock_quantity' => $stock, 'alert_quantity' => $alert]);

    return $product;
}

test('a sale below cost or with a big discount warns the owner, and items reaching the reorder level are reported', function (): void {
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'chat_id' => '1001', 'linked_at' => now(),
        'notifications' => ['risky_sales' => true, 'low_stock' => true]]);
    $sugar = telegramTestProduct($this, 'سكر', 4);
    $rice = telegramTestProduct($this, 'أرز', 50);
    $order = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'INV-100',
        'type' => 'pos', 'status' => 'pending', 'subtotal' => 40, 'discount' => 10, 'total' => 30, 'paid_amount' => 30]);
    OrderItem::query()->create(['tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'product_id' => $sugar->id, 'quantity' => 2,
        'unit_price' => 5, 'total_price' => 10, 'cost_price' => 8, 'total_cost' => 16]);
    OrderItem::query()->create(['tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'product_id' => $rice->id, 'quantity' => 3,
        'unit_price' => 10, 'total_price' => 30, 'cost_price' => 8, 'total_cost' => 24]);
    $order->update(['status' => 'completed']);

    app()->terminate();

    $texts = collect(($this->sentTexts)())->implode("\n");
    expect($texts)->toContain('فاتورة تحتاج مراجعة')->toContain('INV-100')->toContain('خصم كبير: 10.00 (25.0%)')
        ->toContain('سكر: بيع 10.00 والتكلفة 16.00')->toContain('بأقل من تكلفتها')
        ->toContain('أصناف وصلت حد الطلب')->toContain('سكر: المتبقي 4')->not->toContain('أرز: المتبقي');
});

test('a normal sale sends no review warning', function (): void {
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'chat_id' => '1001', 'linked_at' => now(),
        'notifications' => ['risky_sales' => true, 'low_stock' => true]]);
    $rice = telegramTestProduct($this, 'أرز', 50);
    $order = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'INV-101',
        'type' => 'pos', 'status' => 'pending', 'subtotal' => 30, 'discount' => 1, 'total' => 29, 'paid_amount' => 29]);
    OrderItem::query()->create(['tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'product_id' => $rice->id, 'quantity' => 3,
        'unit_price' => 10, 'total_price' => 30, 'cost_price' => 8, 'total_cost' => 24]);
    $order->update(['status' => 'completed']);

    app()->terminate();

    Http::assertNothingSent();
});

test('staff who chose problems only hear about a shift with a cash difference', function (): void {
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'chat_id' => '1001', 'linked_at' => now(),
        'notifications' => ['shift_problems' => true]]);
    $balanced = Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->owner->id,
        'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $balanced->update(['status' => 'closed', 'closed_at' => now(), 'expected_cash' => 100, 'actual_cash' => 100, 'difference' => 0]);
    app()->terminate();
    Http::assertNothingSent();

    app()->forgetScopedInstances();
    $short = Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->owner->id,
        'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $short->update(['status' => 'closed', 'closed_at' => now(), 'expected_cash' => 100, 'actual_cash' => 95, 'difference' => -5]);
    app()->terminate();

    expect(($this->sentTexts)())->toHaveCount(1)->and(($this->sentTexts)()[0])->toContain('عجز ⚠️: 5.00');
});

test('the owner gets the weekly report as a PDF with the key figures', function (): void {
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'chat_id' => '1001', 'linked_at' => now(),
        'notifications' => ['weekly_report' => true]]);
    $rice = telegramTestProduct($this, 'أرز', 50);
    $this->travelTo(now()->subDays(3));
    $order = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'W-1',
        'type' => 'pos', 'status' => 'completed', 'subtotal' => 100, 'total' => 100, 'total_cost' => 60, 'paid_amount' => 100]);
    OrderItem::query()->create(['tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'product_id' => $rice->id, 'quantity' => 10,
        'unit_price' => 10, 'total_price' => 100, 'cost_price' => 6, 'total_cost' => 60]);
    Payment::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'type' => 'payment', 'payment_date' => now(),
        'payment_method' => 'cash', 'voucher_number' => 'EXP-1', 'amount' => 15, 'expense_category' => 'كهرباء']);
    $this->travelBack();

    $this->artisan('telegram:period-report week')->expectsOutputToContain('sent to 1 chat(s)')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendDocument')
        && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'caption'
            && str_contains($part['contents'], 'التقرير الأسبوعي') && str_contains($part['contents'], 'المبيعات: 100.00')
            && str_contains($part['contents'], 'مجمل الربح: 40.00') && str_contains($part['contents'], 'صافي الربح التقريبي: 25.00')));
});

test('the owner asks the bot for today, an item stock and the biggest debts', function (): void {
    TelegramLink::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'chat_id' => '1001', 'linked_at' => now()]);
    telegramTestProduct($this, 'سكر أبيض', 3);
    Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'T-1',
        'type' => 'pos', 'status' => 'completed', 'subtotal' => 45, 'total' => 45, 'paid_amount' => 45]);

    ($this->webhook)('/today', '1001')->assertOk();
    ($this->webhook)('/stock سكر', '1001')->assertOk();
    ($this->webhook)('/debts', '1001')->assertOk();

    $texts = ($this->sentTexts)();
    expect($texts[0])->toContain('ملخص اليوم')->toContain('45.00')
        ->and($texts[1])->toContain('سكر أبيض')->toContain('الفرع الرئيسي: 3 ⚠️')
        ->and($texts[2])->toContain('أبو أحمد: 150.00');
});

test('a customer cannot use the staff commands and gets their statement on request', function (): void {
    ($this->linkCustomer)();

    ($this->webhook)('/debts')->assertOk();
    ($this->webhook)('/statement')->assertOk();

    expect(($this->sentTexts)()[0])->toContain('لموظفي المتجر المربوطين فقط');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendDocument')
        && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'caption' && str_contains($part['contents'], 'كشف حسابك')));
});

test('customers who owe and paid nothing lately get one reminder per period, only when the owner turned it on', function (): void {
    ($this->linkCustomer)();
    $this->artisan('telegram:debt-reminders')->expectsOutputToContain('sent to 0 customer(s)')->assertSuccessful();

    TenantSetting::set($this->tenant->id, 'telegram_debt_reminder_days', '7');
    $this->artisan('telegram:debt-reminders')->expectsOutputToContain('sent to 1 customer(s)')->assertSuccessful();
    $this->artisan('telegram:debt-reminders')->expectsOutputToContain('sent to 0 customer(s)')->assertSuccessful();

    expect(collect(($this->sentTexts)())->implode("\n"))->toContain('تذكير لطيف')->toContain('رصيدك المستحق: 150.00');
});

test('the owner saves the discount alert and the debt reminder from the Telegram settings', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);

    Livewire::test('pages::settings.telegram')->set('discountAlertPercent', 20)->set('debtReminderDays', 15)->call('saveStoreSettings')->assertHasNoErrors();

    expect(TenantSetting::get($this->tenant->id, 'telegram_discount_alert_percent'))->toBe('20')
        ->and(TenantSetting::get($this->tenant->id, 'telegram_debt_reminder_days'))->toBe('15');
});
