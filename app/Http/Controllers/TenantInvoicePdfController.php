<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\PaymentVoucherAccess;
use App\Services\PdfFilename;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantInvoicePdfController extends Controller
{
    public function sales(Request $request, int $orderId): mixed
    {
        abort_unless($request->user()?->can('sales_invoices.view') || $request->user()?->can('van_sales.view'), 403);

        $order = Order::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->whereKey($orderId)
            // Employees open only the invoices they created; the store owner opens every invoice.
            ->when(! $request->user()->isTenantOwner() && ! $request->user()->isSaaSAdmin(),
                fn ($query) => $query->where('created_by', $request->user()->id))
            ->with(['items.product', 'user', 'branch'])
            ->withSum('payments as collected_amount', 'amount')
            ->firstOrFail();

        return $this->pdfResponse($request, 'pages.tenant.pos.partials.invoice-pdf', [
            'order' => $order,
            'accountBalance' => $order->customer_id ? Party::query()->where('tenant_id', $order->tenant_id)->whereKey($order->customer_id)->value('current_balance') : null,
            'storeName' => $order->branch?->name ?: Tenant::query()->whereKey($order->tenant_id)->value('name') ?: 'المتجر',
        ], PdfFilename::forDocument($order->customer_name ?: ($order->customer_id ? Party::query()->where('tenant_id', $order->tenant_id)->whereKey($order->customer_id)->value('name') : null), $order->invoice_number, 'زبون عام'));
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
            ->get(['pi.product_id', 'pi.quantity', 'pi.unit_cost', 'pi.subtotal', 'p.name as product_name', 'p.product_number']);
        $supplierName = $purchase->supplier_id
            ? Party::query()->where('tenant_id', $purchase->tenant_id)->whereKey($purchase->supplier_id)->value('name')
            : null;
        $branchName = Branch::query()
            ->where('tenant_id', $purchase->tenant_id)
            ->whereKey($purchase->branch_id)
            ->value('name');

        return $this->pdfResponse($request, 'pages.tenant.purchases.partials.invoice-pdf', [
            'purchase' => $purchase,
            'items' => $items,
            'supplierName' => $supplierName ?: 'مورد عام',
            'accountBalance' => $purchase->supplier_id ? Party::query()->where('tenant_id', $purchase->tenant_id)->whereKey($purchase->supplier_id)->value('current_balance') : null,
            'storeName' => $branchName ?: Tenant::query()->whereKey($purchase->tenant_id)->value('name') ?: 'المتجر',
        ], PdfFilename::forDocument($supplierName, $purchase->reference_number, 'مورد عام'));
    }

    public function voucher(Request $request, string $type, int $paymentId): mixed
    {
        abort_unless(in_array($type, ['receipt', 'payment'], true), 404);
        abort_unless($request->user()?->can($type === 'receipt' ? 'accounting.receipt.view' : 'accounting.voucher.view'), 403);
        abort_if($type === 'payment' && ! app(PaymentVoucherAccess::class)->available($request), 403);
        $tenantId = (int) (session('active_tenant_id') ?? $request->user()->tenant_id);
        $voucher = Payment::query()->visibleTo($request->user())->where('tenant_id', $tenantId)->where('type', $type)
            ->with(['party', 'creator', 'branch', 'tenant'])->findOrFail($paymentId);

        return $this->pdfResponse($request, 'pages.tenant.accounting.partials.voucher-pdf', ['voucher' => $voucher],
            PdfFilename::forDocument($voucher->party?->name, $voucher->voucher_number ?: (string) $voucher->id, $type === 'receipt' ? 'سند قبض' : 'سند صرف'));
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
            ->get(['item.*', 'products.name', 'products.product_number']);
        $party = Party::query()->where('tenant_id', $tenantId)->find($kind === 'sale' ? $return->customer_id : $return->supplier_id);
        $branchName = Branch::query()->where('tenant_id', $tenantId)->whereKey($return->branch_id)->value('name');
        $showTax = TenantSetting::getBool($tenantId, 'show_tax', true);

        return $this->pdfResponse($request, 'pages.tenant.returns.partials.invoice-pdf', compact('return', 'items', 'party', 'branchName', 'kind', 'showTax'),
            PdfFilename::forDocument($party?->name ?? ($kind === 'sale' ? ($return->customer_name ?? null) : null), $kind === 'sale' ? $return->invoice_number : $return->reference_number, $kind === 'sale' ? 'زبون' : 'مورد'));
    }

    /**
     * Renders a document PDF. With "?inline=1" the browser shows it so the print button prints this exact PDF.
     *
     * @param  array<string, mixed>  $data
     */
    private function pdfResponse(Request $request, string $view, array $data, string $filename): StreamedResponse
    {
        $data['documentTitle'] = Str::beforeLast($filename, '.pdf');

        return response()->streamDownload(function () use ($view, $data): void {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView($view, $data);
            echo $pdf->output();
        }, $filename, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store'], $request->boolean('inline') ? 'inline' : 'attachment');
    }
}
