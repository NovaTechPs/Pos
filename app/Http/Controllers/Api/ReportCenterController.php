<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use App\Services\PosProductSearch;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The web «مركز التقارير» for the POS app: the same reports, filters, columns and totals,
 * built by the same ReportingService, as JSON.
 */
class ReportCenterController extends Controller
{
    private const PER_PAGE = 30;

    /** Reports are for staff accounts only, never store customers. */
    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** Reports the user may open, grouped as on the web, and the branches they may pick. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->staff($request);
        $allowed = (new ReportingService($user, null, false))->availableReports();
        abort_if($allowed === [], 403, 'لا تملك صلاحية على أي تقرير.');
        $groups = [];
        foreach (ReportingService::NAVIGATION_GROUPS as $group => $keys) {
            $reports = [];
            foreach ($keys as $key) {
                if (! isset($allowed[$key])) {
                    continue;
                }
                $allowedBranchIds = ReportingService::allowedBranchIds($user, $key);
                $reports[] = [
                    'key' => $key,
                    'title' => $allowed[$key][0],
                    'all_branches' => $allowedBranchIds === null,
                    'branch_ids' => $allowedBranchIds,
                ];
            }
            if ($reports !== []) {
                $groups[] = ['name' => $group, 'reports' => $reports];
            }
        }

        return response()->json([
            'groups' => $groups,
            'branches' => Branch::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name']),
            'own_branch_id' => $user->branch_id ? (int) $user->branch_id : null,
            'can_see_profit' => $user->can('reports.profit'),
        ]);
    }

    /** One page of a report, its columns, totals and notes, with cells formatted as on the web. */
    public function show(Request $request, string $report): JsonResponse
    {
        abort_unless(isset(ReportingService::REPORTS[$report]), 404);
        $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($report === 'statement') {
            $request->validate(['party_id' => ['required', 'integer', 'min:1']], ['party_id.required' => 'اختر زبوناً أو مورداً أولاً.']);
        }
        $branchId = $request->integer('branch_id') ?: null;
        $service = new ReportingService($this->staff($request), $branchId, false, $report);
        $filters = [
            'from' => $request->query('from', today()->startOfMonth()->toDateString()),
            'to' => $request->query('to', today()->toDateString()),
            'party_id' => $request->query('party_id'),
            'product_id' => $request->query('product_id'),
            'search' => (string) $request->query('search', ''),
            'column_filters' => (array) $request->query('column_filters', []),
            'party_type' => $request->query('party_type', 'all'),
            'balance_side' => $request->query('balance_side', 'all'),
            'stock_filter' => $request->query('stock_filter', 'all'),
            'source' => (string) $request->query('source', ''),
            'sale_kind' => (string) $request->query('sale_kind', ''),
            'invoice_scope' => (string) $request->query('invoice_scope', ''),
            'cost_method' => $request->query('cost_method', 'recorded'),
            'product_view' => $request->query('product_view', 'summary'),
            'voucher_type' => (string) $request->query('voucher_type', ''),
            'payment_method' => (string) $request->query('payment_method', ''),
        ];
        $definition = $service->prepare($report, $filters);
        $rows = $definition['query']->paginate(self::PER_PAGE);
        $columns = $definition['columns'];

        return response()->json([
            'key' => $report,
            'title' => ReportingService::REPORTS[$report][0],
            'from' => $filters['from'],
            'to' => $filters['to'],
            'branch_id' => $service->branchId,
            'branch_name' => $service->branchId
                ? Branch::query()->whereKey($service->branchId)->value('name')
                : 'كل الفروع',
            'columns' => collect($columns)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values(),
            'rows' => collect($rows->items())->map(fn (object $row): array => collect($columns)
                ->map(fn (string $label, string $key): string => ReportingService::formatCell($key, $row->{$key} ?? null))
                ->values()->all())->all(),
            'summary' => collect($definition['summary'])->map(fn (mixed $value, string $label): array => [
                'label' => $label,
                'value' => number_format((float) $value, 2, '.', ','),
            ])->values(),
            'notes' => $definition['notes'],
            'page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'total' => $rows->total(),
        ]);
    }

    /** Customers and suppliers for the report filters, by name, code or phone. */
    public function parties(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'in:all,customer,supplier'],
        ]);
        $search = trim($validated['search'] ?? '');
        $types = match ($validated['type'] ?? 'all') {
            'customer' => ['customer', 'both'],
            'supplier' => ['supplier', 'both'],
            default => ['customer', 'supplier', 'both'],
        };
        $parties = Party::query()->where('tenant_id', $this->staff($request)->tenant_id)
            ->whereIn('type', $types)
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('code', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.$search.'%')))
            ->orderBy('name')->orderBy('id')->limit(50)
            ->get(['id', 'name', 'code', 'type', 'current_balance']);

        return response()->json(['parties' => $parties]);
    }

    /** Products for the report filters, matched like the POS search (name, number or barcode). */
    public function products(Request $request, PosProductSearch $search): JsonResponse
    {
        $validated = $request->validate(['search' => ['required', 'string', 'max:120']]);
        $tenantId = (int) $this->staff($request)->tenant_id;
        $text = trim($validated['search']);
        $find = fn (callable $match) => Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->tap(fn ($query) => $match($query->getQuery()))
            ->orderBy('name')->limit(30)->get(['id', 'name', 'product_number']);
        // Prefix matches first, then any part of the name, as the POS search does.
        $products = $find(fn ($query) => $search->apply($query, $text, $tenantId));
        if ($products->isEmpty()) {
            $products = $find(fn ($query) => $search->applyContains($query, $text));
        }

        return response()->json(['products' => $products]);
    }
}
