<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Party;
use App\Services\OnlineShop;
use App\Services\PdfFilename;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A shop customer downloads one of their own invoices, in the same layout the store prints. */
class ShopInvoiceController extends Controller
{
    public function __invoke(Request $request, OnlineShop $onlineShop, string $shop, int $order): StreamedResponse
    {
        $branch = $onlineShop->branch($shop);
        $account = $onlineShop->customer($branch);
        abort_unless($account, 403);

        $invoice = Order::withoutGlobalScopes()->where('tenant_id', $account->tenant_id)->where('customer_id', $account->party_id)
            ->whereNull('deleted_at')->where('status', 'completed')->whereKey($order)
            ->with(['items' => fn ($items) => $items->withoutGlobalScopes(), 'items.product' => fn ($product) => $product->withoutGlobalScopes(), 'user', 'branch'])
            ->firstOrFail();
        $filename = PdfFilename::forDocument($account->party?->name, $invoice->invoice_number, 'زبون');

        return response()->streamDownload(function () use ($invoice, $account, $filename): void {
            echo app('dompdf.wrapper')->loadView('pages.tenant.pos.partials.invoice-pdf', [
                'order' => $invoice,
                'accountBalance' => Party::withoutGlobalScopes()->whereKey($account->party_id)->value('current_balance'),
                'storeName' => $invoice->branch?->name ?: 'المتجر',
                'documentTitle' => Str::beforeLast($filename, '.pdf'),
            ])->output();
        }, $filename, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }
}
