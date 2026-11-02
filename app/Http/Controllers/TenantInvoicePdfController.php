<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Party;
use App\Models\Tenant;
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
            ->firstOrFail();

        return response()->streamDownload(function () use ($order): void {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('pages.tenant.pos.partials.invoice-pdf', [
                'order' => $order,
                'storeName' => Tenant::query()->whereKey($order->tenant_id)->value('name') ?: $order->branch?->name ?: 'المتجر',
            ]);

            echo $pdf->output();
        }, 'sales-invoice-'.$order->invoice_number.'.pdf');
    }

    public function purchase(Request $request, int $purchaseId): mixed
    {
        abort_unless($request->user()?->can('purchases.view'), 403);

        $purchase = DB::table('purchases')
            ->where('tenant_id', $request->user()->tenant_id)
            ->whereKey($purchaseId)
            ->firstOrFail();
        $items = DB::table('purchase_items as pi')
            ->leftJoin('products as p', 'p.id', '=', 'pi.product_id')
            ->where('pi.purchase_id', $purchase->id)
            ->orderBy('pi.id')
            ->get(['pi.quantity', 'pi.unit_cost', 'pi.subtotal', 'p.name as product_name']);
        $supplierName = $purchase->supplier_id
            ? Party::query()->where('tenant_id', $purchase->tenant_id)->whereKey($purchase->supplier_id)->value('name')
            : null;

        return response()->streamDownload(function () use ($purchase, $items, $supplierName): void {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('pages.tenant.purchases.partials.invoice-pdf', [
                'purchase' => $purchase,
                'items' => $items,
                'supplierName' => $supplierName ?: 'مورد عام',
                'storeName' => Tenant::query()->whereKey($purchase->tenant_id)->value('name') ?: 'المتجر',
            ]);

            echo $pdf->output();
        }, 'purchase-invoice-'.$purchase->reference_number.'.pdf');
    }
}
