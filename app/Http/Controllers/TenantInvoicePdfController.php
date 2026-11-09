<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantInvoicePdfController extends Controller
{
    public function sales(Request $request, int $orderId): mixed
    {
        abort_unless($request->user()?->can('sales_invoices.view'), 403);

        $order = Order::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->whereKey($orderId)
            ->with(['items.product', 'user', 'branch'])
            ->withSum('payments as collected_amount', 'amount')
            ->firstOrFail();

        return response()->streamDownload(function () use ($order): void {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('pages.tenant.pos.partials.invoice-pdf', [
                'order' => $order,
                'accountBalance' => $order->customer_id ? Party::query()->where('tenant_id', $order->tenant_id)->whereKey($order->customer_id)->value('current_balance') : null,
                'storeName' => $order->branch?->name ?: Tenant::query()->whereKey($order->tenant_id)->value('name') ?: 'المتجر',
            ]);

            echo $pdf->output();
        }, 'sales-invoice-'.$order->invoice_number.'.pdf');
    }

    public function purchase(Request $request, int $purchaseId): mixed
    {
        abort_unless($request->user()?->can('purchases.view'), 403);

        $purchase = DB::table('purchases')
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('id', $purchaseId)
            ->firstOrFail();
        $items = DB::table('purchase_items as pi')
            ->leftJoin('products as p', 'p.id', '=', 'pi.product_id')
            ->where('pi.purchase_id', $purchase->id)
            ->orderBy('pi.id')
            ->get(['pi.quantity', 'pi.unit_cost', 'pi.subtotal', 'p.name as product_name']);
        $supplierName = $purchase->supplier_id
            ? Party::query()->where('tenant_id', $purchase->tenant_id)->whereKey($purchase->supplier_id)->value('name')
            : null;
        $branchName = Branch::query()
            ->where('tenant_id', $purchase->tenant_id)
            ->whereKey($purchase->branch_id)
            ->value('name');

        return response()->streamDownload(function () use ($purchase, $items, $supplierName, $branchName): void {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('pages.tenant.purchases.partials.invoice-pdf', [
                'purchase' => $purchase,
                'items' => $items,
                'supplierName' => $supplierName ?: 'مورد عام',
                'accountBalance' => $purchase->supplier_id ? Party::query()->where('tenant_id', $purchase->tenant_id)->whereKey($purchase->supplier_id)->value('current_balance') : null,
                'storeName' => $branchName ?: Tenant::query()->whereKey($purchase->tenant_id)->value('name') ?: 'المتجر',
            ]);

            echo $pdf->output();
        }, 'purchase-invoice-'.$purchase->reference_number.'.pdf');
    }

    public function voucher(Request $request, string $type, int $paymentId): mixed
    {
        abort_unless(in_array($type, ['receipt', 'payment'], true), 404);
        abort_unless($request->user()?->can($type === 'receipt' ? 'accounting.receipt.view' : 'accounting.voucher.view'), 403);
        $tenantId = (int) (session('active_tenant_id') ?? $request->user()->tenant_id);
        $voucher = Payment::query()->where('tenant_id', $tenantId)->where('type', $type)
            ->with(['party', 'creator', 'branch', 'tenant'])->findOrFail($paymentId);

        return response()->streamDownload(function () use ($voucher): void {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('pages.tenant.accounting.partials.voucher-pdf', ['voucher' => $voucher]);
            echo $pdf->output();
        }, 'voucher-'.$type.'-'.$voucher->id.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function returns(Request $request, string $kind, int $returnId): mixed
    {
        abort_unless($request->user()?->can('returns.view'), 403);
        abort_unless(in_array($kind, ['sale', 'purchase'], true), 404);
        $tenantId = (int) (session('active_tenant_id') ?? $request->user()->tenant_id);
        $query = DB::table($kind === 'sale' ? 'orders' : 'purchase_returns')->where('tenant_id', $tenantId)
            ->when($request->user()->branch_id, fn ($query, $branch) => $query->where('branch_id', $branch));
        if ($kind === 'sale') {
            $query->where('type', 'return')->whereNull('deleted_at')->where('source', 'web')->whereNull('original_order_id')->whereNull('shift_id');
        } else {
            $query->whereNull('purchase_id');
        }
        $return = $query->where('id', $returnId)->first();
        abort_unless($return, 404);
        $items = DB::table($kind === 'sale' ? 'order_items as item' : 'purchase_return_items as item')
            ->join('products', function ($join) use ($tenantId): void {
                $join->on('products.id', '=', 'item.product_id')->where('products.tenant_id', $tenantId);
            })->where('item.tenant_id', $tenantId)->where($kind === 'sale' ? 'item.order_id' : 'item.purchase_return_id', $returnId)
            ->get(['item.*', 'products.name']);
        $party = Party::query()->where('tenant_id', $tenantId)->find($kind === 'sale' ? $return->customer_id : $return->supplier_id);
        $branchName = Branch::query()->where('tenant_id', $tenantId)->whereKey($return->branch_id)->value('name');
        $showTax = TenantSetting::getBool($tenantId, 'show_tax', true);

        return response()->streamDownload(function () use ($return, $items, $party, $branchName, $kind, $showTax): void {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('pages.tenant.returns.partials.invoice-pdf', compact('return', 'items', 'party', 'branchName', 'kind', 'showTax'));
            echo $pdf->output();
        }, 'return-'.$kind.'-'.$returnId.'.pdf');
    }
}
