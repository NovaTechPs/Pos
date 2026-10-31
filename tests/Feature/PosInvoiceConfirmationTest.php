<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $tenant = Tenant::create(['name' => 'Confirm tenant', 'is_active' => true]);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    Shift::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'opened_by' => $user->id, 'status' => 'open', 'opened_at' => now(), 'opening_cash' => 0]);
    $this->product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Coffee', 'cost_price' => 2]);
    BranchProduct::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 10, 'retail_price' => 12]);
});

test('requesting a new invoice requires confirmation and cancellation preserves the draft', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)->set('notes', 'Original note');
    $cart = $component->get('cart');
    $component->call('clearCart')->assertSet('showClearInvoiceModal', true)->assertSet('cart', $cart)->assertSet('notes', 'Original note')
        ->assertSee('تأكيد إفراغ الفاتورة')->call('cancelInvoiceDialogs')->assertSet('showClearInvoiceModal', false)->assertSet('cart', $cart);
    $component->call('confirmClearCart')->assertSet('cart', $cart);
    Livewire::test('pages::tenant.pos.sales')->assertSet('cart', $cart)->assertSet('notes', 'Original note');
    $component->call('clearCart')->call('confirmClearCart')->assertSet('cart', [])->assertSet('notes', '')->assertSet('showClearInvoiceModal', false);
    Livewire::test('pages::tenant.pos.sales')->assertSet('cart', []);
    expect(Order::count())->toBe(0);
});

test('hold prompts for a note and cancellation does not suspend or overwrite the invoice', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)->set('notes', 'Original note');
    $cart = $component->get('cart');
    $component->call('holdInvoice')->assertSet('showHoldNotesModal', true)->assertSet('holdNotes', 'Original note')->assertSet('heldInvoices', [])
        ->set('holdNotes', 'Cancelled note')->call('cancelInvoiceDialogs')->assertSet('notes', 'Original note')->assertSet('cart', $cart)->assertSet('heldInvoices', []);
    $component->call('confirmHoldInvoice')->assertSet('heldInvoices', [])->assertSet('cart', $cart);
});

test('a confirmed hold stores and displays its escaped note and restores it with the cart', function (): void {
    $note = 'طلب أحمد <script>alert(1)</script>';
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)->call('holdInvoice')
        ->set('holdNotes', $note)->call('confirmHoldInvoice')->assertSet('cart', [])->assertSet('showHoldNotesModal', false)
        ->assertSet('heldInvoices.0.notes', $note)->set('showHeldModal', true)->assertSee($note)->assertDontSeeHtml('<script>alert(1)</script>');
    $component->call('restoreHeldInvoice', 0)->assertSet('notes', $note)->assertSet('heldInvoices', []);
    expect($component->get('cart'))->toHaveCount(1);
    expect(Order::count())->toBe(0);
});

test('oversized hold notes keep the invoice and dialog intact', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)->call('holdInvoice')
        ->set('holdNotes', str_repeat('x', 501))->call('confirmHoldInvoice')->assertHasErrors(['holdNotes' => 'max'])
        ->assertSet('showHoldNotesModal', true)->assertSet('heldInvoices', []);
    expect($component->get('cart'))->toHaveCount(1);
});
