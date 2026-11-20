<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر عروض نقطة البيع']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'عصير العروض', 'cost_price' => 2]);
    BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'retail_price' => 5, 'stock_quantity' => 10]);
    $this->offer = ProductOffer::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id,
        'offer_quantity' => 3, 'offer_price' => 12, 'start_at' => now()->subDay(), 'end_at' => null, 'is_active' => true, 'created_by' => $this->user->id]);
    ProductOffer::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id,
        'offer_quantity' => 9, 'offer_price' => 30, 'start_at' => now()->subDays(10), 'end_at' => now()->subDays(3), 'is_active' => true, 'created_by' => $this->user->id]);
});

test('the POS product editor lists the current offers and edits one in place', function (): void {
    $editor = Livewire::test('pages::tenant.products.index', ['posEmbedded' => true, 'editProductId' => $this->product->id])
        ->assertSeeHtml('data-product-current-offers')
        ->assertSeeHtml('wire:click="editOffer('.$this->offer->id.')"')
        ->assertSee('3 بـ 12.00')
        ->assertDontSee('9 بـ 30.00');

    $editor->call('editOffer', $this->offer->id)
        ->assertSet('showOfferModal', true)->assertSet('offerId', $this->offer->id)
        ->set('offerQuantity', '4')->set('offerPrice', '15')
        ->call('saveOffer')->assertHasNoErrors()
        ->assertSet('showOfferModal', false)
        ->assertSee('4 بـ 15.00');

    expect($this->offer->fresh())->offer_quantity->toEqual(4)->offer_price->toEqual(15);
});

test('the POS product editor still adds a new offer when saving the product', function (): void {
    Livewire::test('pages::tenant.products.index', ['posEmbedded' => true, 'editProductId' => $this->product->id])
        ->set('newOfferQuantity', '6')->set('newOfferPrice', '25')
        ->call('save')->assertHasNoErrors();

    expect(ProductOffer::query()->where('product_id', $this->product->id)->where('offer_quantity', 6)->where('offer_price', 25)->exists())->toBeTrue();
});
