<?php

use App\Models\Branch;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Voucher visibility']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'محاسب']);
    foreach (['accounting.voucher.view', 'accounting.voucher.create', 'accounting.receipt.view', 'accounting.receipt.create'] as $name) {
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name, 'group' => 'test']));
    }
    $this->employee = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'is_owner' => false]);
    $this->colleague = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'is_owner' => false]);
    $party = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Account', 'type' => 'both', 'is_active' => true]);
    $voucher = fn (User $creator, string $type, string $number) => Payment::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'created_by' => $creator->id, 'party_id' => $party->id,
        'type' => $type, 'voucher_number' => $number, 'amount' => 25, 'payment_method' => 'cash', 'payment_date' => now(),
    ]);
    foreach (['receipt' => 'REC', 'payment' => 'PAY'] as $type => $prefix) {
        $this->{$type.'Own'} = $voucher($this->employee, $type, $prefix.'-OWN-1');
        $this->{$type.'Other'} = $voucher($this->colleague, $type, $prefix.'-OTHER-1');
    }
});

test('employees see, reopen, print and delete only the vouchers they created', function (string $page, string $type): void {
    $this->actingAs($this->employee)->withSession(['active_tenant_id' => $this->tenant->id]);
    $own = $this->{$type.'Own'};
    $other = $this->{$type.'Other'};

    $component = Livewire::test('pages::tenant.accounting.'.$page)
        ->assertSee($own->voucher_number)->assertDontSee($other->voucher_number)
        ->call('edit', $own->id)->assertSet('paymentId', $own->id);
    $component->call('edit', $other->id)->assertNotFound();
    Livewire::test('pages::tenant.accounting.'.$page)->call('print', $other->id)->assertNotFound();
    Livewire::test('pages::tenant.accounting.'.$page)->set('tableSearch', $other->voucher_number)->assertDontSee($other->voucher_number);

    $this->get(route('tenant.vouchers.pdf', [$type, $other->id]))->assertNotFound();
    $this->get(route('tenant.vouchers.pdf', [$type, $own->id]))->assertOk();
    Livewire::test('pages::tenant.accounting.'.$page)->call('confirmDelete', $other->id)->assertNotFound();
    expect(Payment::query()->find($other->id))->not->toBeNull();
})->with([['receipts', 'receipt'], ['payment', 'payment']]);

test('the store owner still sees every voucher', function (string $page, string $type): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::tenant.accounting.'.$page)
        ->assertSee($this->{$type.'Own'}->voucher_number)->assertSee($this->{$type.'Other'}->voucher_number)
        ->call('edit', $this->{$type.'Other'}->id)->assertSet('paymentId', $this->{$type.'Other'}->id);
})->with([['receipts', 'receipt'], ['payment', 'payment']]);

test('vouchers can be browsed like the invoice archive, through the employee own vouchers only', function (string $page, string $type): void {
    $this->actingAs($this->employee)->withSession(['active_tenant_id' => $this->tenant->id]);
    $first = $this->{$type.'Own'};
    $second = Payment::query()->create([...$first->only(['tenant_id', 'branch_id', 'created_by', 'party_id', 'type', 'amount', 'payment_method']),
        'voucher_number' => strtoupper(substr($type, 0, 3)).'-OWN-2', 'payment_date' => now()]);

    Livewire::test('pages::tenant.accounting.'.$page)->call('openCreate')
        ->assertSee('data-voucher-archive', false)->assertSee('استعلام')
        ->call('previousVoucher')->assertSet('paymentId', $second->id)->assertSet('voucherNumber', $second->voucher_number)
        ->call('previousVoucher')->assertSet('paymentId', $first->id)
        ->call('previousVoucher')->assertSet('paymentId', $first->id)
        ->call('nextVoucher')->assertSet('paymentId', $second->id)
        ->call('nextVoucher')->assertSet('paymentId', null)->assertSet('showForm', true)
        ->set('voucherArchiveSearch', $this->{$type.'Other'}->voucher_number)->call('searchVoucherArchive')->assertSet('paymentId', null)
        ->set('voucherArchiveSearch', '١')->call('searchVoucherArchive')->assertSet('paymentId', $first->id)
        ->set('voucherArchiveSearch', $second->voucher_number)->call('searchVoucherArchive')->assertSet('paymentId', $second->id)
        ->assertSet('voucherArchiveSearch', '');
})->with([['receipts', 'receipt'], ['payment', 'payment']]);

test('payment vouchers are hidden on android devices and shown on the desktop app and computers', function (User $user): void {
    $this->actingAs($user)->withSession(['active_tenant_id' => $this->tenant->id]);
    $android = ['User-Agent' => 'Mozilla/5.0 (Linux; Android 13; Q6 Pro) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36'];
    $desktop = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 WaslaPOS/0.1.10 Chrome/140 Electron/45.0.0 Safari/537.36'];

    $this->withHeaders($android)->get(route('tenant.payment'))->assertForbidden();
    $this->withHeaders($android)->get(route('tenant.vouchers.pdf', ['payment', $this->paymentOwn->id]))->assertForbidden();
    $this->withHeaders($android)->get(route('tenant.receipts'))->assertOk()->assertDontSee(route('tenant.payment'), false);

    $this->withHeaders($desktop)->get(route('tenant.payment'))->assertOk();
    $this->withHeaders($desktop)->get(route('tenant.receipts'))->assertOk()->assertSee(route('tenant.payment'), false);
    $this->withHeaders($desktop)->get(route('tenant.vouchers.pdf', ['payment', $this->paymentOwn->id]))->assertOk();
})->with([
    'employee' => fn () => $this->employee,
    'owner' => fn () => $this->owner,
]);
