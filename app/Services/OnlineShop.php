<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\CustomerAccount;
use App\Models\CustomerOrder;
use App\Models\Party;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A store's public online shop. It is reached through a branch's domain name, shows that branch's
 * products marked for the website at retail prices (wholesale prices for wholesale accounts), and
 * turns a cart into an order request for staff to review.
 */
class OnlineShop
{
    public const SETTING = 'online_shop_enabled';

    /** The branch whose shop the link opens, or 404 when the store has not opened its shop. */
    public function branch(string $slug): Branch
    {
        $branch = Branch::withoutGlobalScopes()->where('domain', strtolower(trim($slug)))->first();
        abort_unless($branch && Tenant::query()->whereKey($branch->tenant_id)->where('is_active', true)->exists(), 404);
        abort_unless(TenantSetting::getBool((int) $branch->tenant_id, self::SETTING), 404);

        return $branch;
    }

    /** The signed-in customer when the account belongs to this store, otherwise null. */
    public function customer(Branch $branch): ?CustomerAccount
    {
        $account = auth('customer')->user();

        return $account instanceof CustomerAccount && (int) $account->tenant_id === (int) $branch->tenant_id && $account->is_active
            ? $account : null;
    }

    public function priceList(?CustomerAccount $account): string
    {
        return $account?->isWholesale() ? 'wholesale' : 'retail';
    }

    /** @param  object  $stock  a branch product, or a product row joined with its branch prices */
    public static function price(object $stock, string $priceList): float
    {
        $wholesale = (float) ($stock->wholesale_price ?? 0);

        return round($priceList === 'wholesale' && $wholesale > 0 ? $wholesale : (float) ($stock->retail_price ?? 0), 2);
    }

    /**
     * Products on sale in the branch's shop: marked for the website and priced. Stock shows only as
     * available or not.
     *
     * @return Collection<int, array{id: int, name: string, product_number: ?string, price: float, available: bool, image: ?string, category_id: ?int}>
     */
    public function catalog(Branch $branch, string $priceList, string $search = '', ?int $categoryId = null, int $limit = 60): Collection
    {
        $search = app(PosProductSearch::class)->normalize(trim($search));

        return Product::withoutGlobalScopes()->where('products.tenant_id', $branch->tenant_id)->whereNull('products.deleted_at')
            ->where('products.show_in_website', true)
            ->join('branch_products', fn ($join) => $join->on('branch_products.product_id', '=', 'products.id')->where('branch_products.branch_id', $branch->id))
            ->where('branch_products.retail_price', '>', 0)
            ->when($categoryId, fn ($query) => $query->where('products.category_id', $categoryId))
            ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search, $branch): void {
                foreach (array_slice(array_filter(explode(' ', $search)), 0, 6) as $word) {
                    $match->where(fn ($wordQuery) => $wordQuery->where('products.search_name', 'like', '%'.$word.'%')->orWhere('products.name', 'like', '%'.$word.'%'));
                }
                $match->orWhere('products.product_number', $search)
                    ->orWhereExists(fn ($codes) => $codes->selectRaw('1')->from('product_barcodes')->whereColumn('product_barcodes.product_id', 'products.id')
                        ->where('product_barcodes.tenant_id', $branch->tenant_id)->where('product_barcodes.barcode', $search));
            }))
            ->orderByRaw('CASE WHEN branch_products.stock_quantity > 0 THEN 0 ELSE 1 END')->orderBy('products.name')
            ->limit($limit)
            ->get(['products.id', 'products.name', 'products.product_number', 'products.image', 'products.category_id',
                'branch_products.retail_price', 'branch_products.wholesale_price', 'branch_products.stock_quantity'])
            ->map(fn (Product $product): array => [
                'id' => (int) $product->id, 'name' => $product->name, 'product_number' => $product->product_number,
                'price' => self::price($product, $priceList), 'available' => (float) $product->stock_quantity > 0,
                'image' => $product->image ? (Str::startsWith($product->image, ['http://', 'https://']) ? $product->image : Storage::url($product->image)) : null,
                'category_id' => $product->category_id ? (int) $product->category_id : null,
            ]);
    }

    /**
     * The cart's lines with current prices; products no longer on sale drop out.
     *
     * @param  array<int|string, float|int|string>  $cart  product id => quantity
     * @return Collection<int, array{id: int, name: string, price: float, quantity: float, total: float, available: bool}>
     */
    public function cartLines(Branch $branch, string $priceList, array $cart): Collection
    {
        $quantities = collect($cart)->mapWithKeys(fn ($quantity, $productId): array => [(int) $productId => round((float) $quantity, 3)])->filter(fn (float $quantity): bool => $quantity > 0);
        if ($quantities->isEmpty()) {
            return collect();
        }

        return Product::withoutGlobalScopes()->where('products.tenant_id', $branch->tenant_id)->whereNull('products.deleted_at')
            ->where('products.show_in_website', true)->whereIn('products.id', $quantities->keys())
            ->join('branch_products', fn ($join) => $join->on('branch_products.product_id', '=', 'products.id')->where('branch_products.branch_id', $branch->id))
            ->where('branch_products.retail_price', '>', 0)
            ->get(['products.id', 'products.name', 'branch_products.retail_price', 'branch_products.wholesale_price', 'branch_products.stock_quantity'])
            ->map(function (Product $product) use ($quantities, $priceList): array {
                $price = self::price($product, $priceList);
                $quantity = $quantities[(int) $product->id];

                return ['id' => (int) $product->id, 'name' => $product->name, 'price' => $price, 'quantity' => $quantity,
                    'total' => round($price * $quantity, 2), 'available' => (float) $product->stock_quantity > 0];
            })->values();
    }

    /**
     * Turns a cart into an order request. Prices are read again from the branch, never from the browser.
     *
     * @param  array<int|string, float|int|string>  $cart  product id => quantity
     * @param  array{name: string, phone: string, address?: ?string, notes?: ?string}  $contact
     */
    public function placeOrder(Branch $branch, array $cart, array $contact, ?CustomerAccount $account = null): CustomerOrder
    {
        $quantities = collect($cart)->mapWithKeys(fn ($quantity, $productId): array => [(int) $productId => round((float) $quantity, 3)])
            ->filter(fn (float $quantity, int $productId): bool => $productId > 0 && $quantity > 0);
        if ($quantities->isEmpty() || $quantities->count() > 200 || $quantities->contains(fn (float $quantity): bool => $quantity > 100000)) {
            throw ValidationException::withMessages(['cart' => 'السلة فارغة أو الكميات غير صحيحة.']);
        }
        $priceList = $this->priceList($account);
        $stock = BranchProduct::withoutGlobalScopes()->where('tenant_id', $branch->tenant_id)->where('branch_id', $branch->id)
            ->whereIn('product_id', $quantities->keys())->where('retail_price', '>', 0)
            ->whereHas('product', fn ($product) => $product->withoutGlobalScopes()->whereNull('deleted_at')->where('show_in_website', true))
            ->get()->keyBy('product_id');
        if ($stock->count() !== $quantities->count()) {
            throw ValidationException::withMessages(['cart' => 'بعض الأصناف لم تعد متاحة في المتجر. حدّث السلة.']);
        }

        return DB::transaction(function () use ($branch, $quantities, $stock, $priceList, $contact, $account): CustomerOrder {
            $order = CustomerOrder::query()->create([
                'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id,
                'party_id' => $account?->party_id, 'customer_account_id' => $account?->id,
                'reference' => 'WEB-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
                'tracking_token' => Str::random(48),
                'customer_name' => trim($contact['name']), 'customer_phone' => trim($contact['phone']),
                'customer_address' => trim((string) ($contact['address'] ?? '')) ?: null,
                'notes' => trim((string) ($contact['notes'] ?? '')) ?: null,
                'price_list' => $priceList, 'status' => 'new', 'total' => 0,
            ]);
            $total = 0.0;
            foreach ($quantities as $productId => $quantity) {
                $price = self::price($stock[$productId], $priceList);
                $lineTotal = round($price * $quantity, 2);
                $total += $lineTotal;
                $order->items()->create(['tenant_id' => $branch->tenant_id, 'product_id' => $productId, 'quantity' => $quantity, 'unit_price' => $price, 'total' => $lineTotal]);
            }
            $order->update(['total' => round($total, 2)]);

            return $order;
        });
    }

    /** Creates a retail customer and their sign-in. A phone already registered with the store is refused. */
    public function register(Branch $branch, string $name, string $phone, string $password, ?string $address = null): CustomerAccount
    {
        $phone = CustomerAccount::normalizePhone($phone);
        if (CustomerAccount::query()->where('tenant_id', $branch->tenant_id)->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => 'رقم الهاتف مسجل مسبقاً. سجّل الدخول بدلاً من ذلك.']);
        }

        return DB::transaction(function () use ($branch, $name, $phone, $password, $address): CustomerAccount {
            $party = Party::query()->withoutGlobalScopes()->create([
                'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'name' => trim($name), 'phone' => $phone,
                'address' => $address ? trim($address) : null, 'type' => 'customer', 'is_active' => true,
                'opening_balance' => 0, 'current_balance' => 0,
                'created_by' => Tenant::query()->whereKey($branch->tenant_id)->value('owner_id'),
            ]);

            return CustomerAccount::query()->create([
                'tenant_id' => $branch->tenant_id, 'party_id' => $party->id, 'phone' => $phone, 'password' => $password,
                'price_list' => 'retail', 'is_active' => true,
            ]);
        });
    }

    /**
     * The customer's account statement, from the same ledger staff see.
     *
     * @return array{rows: Collection<int, object>, columns: array<string, string>, summary: array<string, mixed>}
     */
    public function statement(CustomerAccount $account): array
    {
        $owner = User::query()->where('tenant_id', $account->tenant_id)->where('is_owner', true)->orderBy('id')->first();
        abort_unless($owner, 404);
        $definition = (new ReportingService($owner, null, false))->prepare('statement', [
            'from' => '2000-01-01', 'to' => now()->toDateString(), 'party_id' => $account->party_id,
            'party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all', 'search' => '',
        ]);

        return ['rows' => $definition['query']->get(), 'columns' => $definition['columns'], 'summary' => $definition['summary']];
    }
}
