<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;
        $branchId = $request->get('branch_id') ?? $user->branch_id;
        abort_if(! $branchId, 422, 'حدد الفرع لعرض التقرير.');

        $ordersQuery = Order::where('tenant_id', $tenantId)->where('branch_id', $branchId);
        $todayOrders = (clone $ordersQuery)->whereDate('created_at', Carbon::today());

        $totalSalesCount = (clone $todayOrders)->where('type', 'pos')->where('status', 'completed')->count();
        $totalSalesRevenue = (clone $todayOrders)->where('type', 'pos')->where('status', 'completed')->sum('total');
        $totalReturnsRevenue = (clone $todayOrders)->where('type', 'return')->where('status', 'completed')->sum('total');
        $netRevenue = $totalSalesRevenue + $totalReturnsRevenue;

        $totalExpenses = Payment::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('type', 'payment')
            ->whereDate('payment_date', Carbon::today())
            ->sum('amount');

        $grossProfit = (clone $todayOrders)->where('type', 'pos')->where('status', 'completed')->sum('total_profit');
        $topProducts = OrderItem::query()
            ->selectRaw('order_items.product_id, products.name, SUM(order_items.quantity) as quantity_sold, SUM(order_items.total_price) as revenue')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.tenant_id', $tenantId)->where('orders.branch_id', $branchId)
            ->where('orders.type', 'pos')->where('orders.status', 'completed')->whereDate('orders.created_at', Carbon::today())
            ->groupBy('order_items.product_id', 'products.name')->orderByDesc('quantity_sold')->limit(5)->get();

        $stockAlerts = Product::query()->where('products.tenant_id', $tenantId)
            ->join('branch_products', 'branch_products.product_id', '=', 'products.id')
            ->where('branch_products.branch_id', $branchId)
            ->whereColumn('branch_products.stock_quantity', '<=', 'branch_products.alert_quantity')
            ->select('products.id', 'products.name', 'branch_products.stock_quantity', 'branch_products.alert_quantity')
            ->orderBy('branch_products.stock_quantity')->limit(20)->get();

        return response()->json([
            'summary' => [
                'total_sales_count' => $totalSalesCount,
                'total_sales_revenue' => round((float) $totalSalesRevenue, 2),
                'total_returns_revenue' => round(abs((float) $totalReturnsRevenue), 2),
                'net_revenue' => round($netRevenue, 2),
                'total_expenses' => round((float) $totalExpenses, 2),
                'net_profit_estimate' => round($netRevenue - $totalExpenses, 2),
                'gross_profit' => round((float) $grossProfit, 2),
                'date' => Carbon::today()->toDateString(),
            ],
            'top_products' => $topProducts,
            'stock_alerts' => $stockAlerts,
        ]);
    }
}
