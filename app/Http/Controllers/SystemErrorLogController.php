<?php

namespace App\Http\Controllers;

use App\Services\SystemErrorLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class SystemErrorLogController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, SystemErrorLog $logs): Response
    {
        abort_unless($request->user()?->isSaaSAdmin(), 403);
        $files = $logs->files();
        $validated = $request->validate([
            'file' => ['nullable', 'string', Rule::in($files)],
            'level' => ['nullable', Rule::in(['error', 'warning'])],
        ]);
        $selectedFile = $validated['file'] ?? ($files[0] ?? null);
        $level = $validated['level'] ?? 'error';

        return response()->view('pages.admin.system-errors', [
            'files' => $files,
            'selectedFile' => $selectedFile,
            'level' => $level,
            'entries' => $selectedFile ? $logs->entries($selectedFile, $level) : [],
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
