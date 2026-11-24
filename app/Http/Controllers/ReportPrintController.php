<?php

namespace App\Http\Controllers;

use App\Services\PdfFilename;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportPrintController extends Controller
{
    /** Rows a PDF export holds at most; the printable page streams the full report. */
    public const PDF_ROW_LIMIT = 2000;

    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate(['report' => ['required', 'string'], 'branch_id' => ['nullable', 'integer', 'min:0'], 'format' => ['nullable', 'in:html,pdf']]);
        $hasBranchFilter = $request->filled('branch_id');
        $branchId = $hasBranchFilter && $request->integer('branch_id') > 0 ? $request->integer('branch_id') : null;
        $report = $request->string('report')->toString();
        $service = new ReportingService($request->user(), $branchId, ! $hasBranchFilter, $report);
        $definition = $service->prepare($report, $request->only(ReportingService::FILTER_KEYS));
        $data = ['title' => ReportingService::REPORTS[$report][0], 'definition' => $definition,
            'from' => $request->string('from')->toString(), 'to' => $request->string('to')->toString(),
            'tenantName' => DB::table('tenants')->where('id', $service->tenantId)->value('name'),
            'branchName' => $service->branchId ? DB::table('branches')->where('tenant_id', $service->tenantId)->where('id', $service->branchId)->value('name') : 'كل الفروع'];

        if ($request->input('format') === 'pdf') {
            $partyName = $request->integer('party_id') > 0
                ? DB::table('parties')->where('tenant_id', $service->tenantId)->where('id', $request->integer('party_id'))->value('name')
                : null;
            $data['filename'] = PdfFilename::forReport($report === 'statement' ? 'كشف حساب' : $data['title'], $partyName, $data['from'], $data['to']);

            return $this->pdf($report, $data);
        }

        return response()->stream(function () use ($definition, $data): void {
            echo view('pages.tenant.reports.print-header', $data)->render();
            foreach ($definition['query']->cursor() as $row) {
                echo view('pages.tenant.reports.print-row', ['row' => $row, 'columns' => $definition['columns']])->render();
            }
            echo '</tbody></table><footer>تم الإنشاء: '.e(now()->format('Y-m-d H:i')).' — '.e(config('app.timezone')).'</footer></body></html>';
        }, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Downloads the report as an A4 landscape PDF.
     *
     * @param  array<string, mixed>  $data
     */
    private function pdf(string $report, array $data): StreamedResponse
    {
        $rows = $data['definition']['query']->limit(self::PDF_ROW_LIMIT + 1)->get();
        $data['truncated'] = $rows->count() > self::PDF_ROW_LIMIT;
        $data['rows'] = $rows->take(self::PDF_ROW_LIMIT);

        return response()->streamDownload(function () use ($data): void {
            echo app('dompdf.wrapper')->loadView('pages.tenant.reports.pdf', $data)->setPaper('a4', 'landscape')->output();
        }, $data['filename'], ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }
}
