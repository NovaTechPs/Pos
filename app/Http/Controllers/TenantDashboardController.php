<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantDashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->can('dashboard.view')) {
            return view('pages.tenant.dashboard');
        }

        $destinations = [
            'wholesale_sales.view' => 'tenant.wholesale-sales',
            'van_sales.view' => 'tenant.wholesale-invoices',
            'pos.view' => 'tenant.pos',
            'sales_invoices.view' => 'tenant.sale-invoices',
            'purchases.view' => 'tenant.purchase-invoices',
            'products.view' => 'tenant.product',
            'customers.view' => 'tenant.customer',
            'returns.view' => 'tenant.returns',
            'branches.view' => 'tenant.branch',
            'employees.view' => 'tenant.employees',
            'roles.view' => 'tenant.role',
            'reports.analytics' => 'tenant.analytics',
        ];

        foreach ($destinations as $permission => $route) {
            if ($user->can($permission)) {
                return redirect()->route($route);
            }
        }

        abort(403, __('This action is unauthorized.'));
    }
}
