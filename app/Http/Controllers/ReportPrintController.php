<?php

namespace App\Http\Controllers;

use App\Services\ReportingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportPrintController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate(['report' => ['required', 'string'], 'branch_id' => ['nullable', 'integer', 'min:1']]);
        $service = new ReportingService($request->user(), $request->filled('branch_id') ? $request->integer('branch_id') : null);
        $report = $request->string('report')->toString();
        $definition = $service->prepare($report, $request->only(['from', 'to', 'party_id', 'product_id', 'search', 'party_type', 'balance_side', 'stock_filter', 'source', 'sale_kind']));
        $data = ['title' => ReportingService::REPORTS[$report][0], 'definition' => $definition,
            'from' => $request->string('from')->toString(), 'to' => $request->string('to')->toString(),
            'tenantName' => DB::table('tenants')->where('id', $service->tenantId)->value('name'),
            'branchName' => $service->branchId ? DB::table('branches')->where('tenant_id', $service->tenantId)->where('id', $service->branchId)->value('name') : 'كل الفروع'];

        return response()->stream(function () use ($definition, $data): void {
            echo view('pages.tenant.reports.print-header', $data)->render();
            foreach ($definition['query']->cursor() as $row) {
                echo view('pages.tenant.reports.print-row', ['row' => $row, 'columns' => $definition['columns']])->render();
            }
            echo '</tbody></table><footer>تم الإنشاء: '.e(now()->format('Y-m-d H:i')).' — '.e(config('app.timezone')).'</footer></body></html>';
        }, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
