<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreviewTenantBackupRequest;
use App\Http\Requests\RestoreTenantBackupRequest;
use App\Models\Tenant;
use App\Services\TenantBackupService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TenantBackupController extends Controller
{
    public function index(Request $request, TenantBackupService $backups): View
    {
        $user = $request->user();
        abort_unless($user->isSaaSAdmin() || $user->isTenantOwner(), 403);
        $tenants = Tenant::query()->when(! $user->isSaaSAdmin(), fn ($q) => $q->whereKey($user->tenant_id))->orderBy('name')->get();
        $tenant = $tenants->firstWhere('id', $request->integer('tenant_id')) ?? $tenants->first();
        $preview = $request->session()->get('tenant_restore_preview');
        if ($preview && ($preview['tenant_id'] !== $tenant?->id || $preview['user_id'] !== $user->id || $preview['expires'] < time())) {
            $preview = null;
        }

        return view('pages.tenant.system.tenant-backups', ['tenants' => $tenants, 'tenant' => $tenant, 'history' => $tenant ? $backups->history($tenant) : [], 'preview' => $preview]);
    }

    public function create(Request $request, Tenant $tenant, TenantBackupService $backups): BinaryFileResponse|RedirectResponse
    {
        $this->authorizeTenant($request, $tenant, 'backup.create');
        try {
            $manifest = $backups->create($tenant);

            return response()->download($backups->path($tenant->id, $manifest['id']), 'WaslaPOS-store-'.$tenant->id.'-'.now()->format('Ymd-His').'.zip', ['Cache-Control' => 'private, no-store']);
        } catch (Throwable $error) {
            report($error);

            return back()->withErrors(['backup' => 'تعذر إنشاء النسخة. تحقق من مساحة التخزين وإعدادات الخادم.']);
        }
    }

    public function download(Request $request, Tenant $tenant, string $backup, TenantBackupService $backups): BinaryFileResponse
    {
        $this->authorizeTenant($request, $tenant, 'backup.create');
        $path = $backups->path($tenant->id, $backup);
        abort_unless(is_file($path), 404);

        return response()->download($path, 'WaslaPOS-store-'.$tenant->id.'-'.$backup.'.zip', ['Cache-Control' => 'private, no-store']);
    }

    public function preview(PreviewTenantBackupRequest $request, Tenant $tenant, TenantBackupService $backups): RedirectResponse
    {
        $token = (string) Str::uuid();
        $directory = $backups->directory($tenant->id).'/incoming';
        File::ensureDirectoryExists($directory, 0700);
        foreach (File::glob($directory.'/*.zip') as $old) {
            if (filemtime($old) < time() - 3600) {
                File::delete($old);
            }
        }
        $path = $directory.'/'.$token.'.zip';
        $request->file('backup')->move($directory, $token.'.zip');
        chmod($path, 0600);
        try {
            $manifest = $backups->inspect($path, $tenant);
            $request->session()->put('tenant_restore_preview', ['token' => $token, 'tenant_id' => $tenant->id, 'user_id' => $request->user()->id,
                'expires' => time() + 1800, 'sha256' => hash_file('sha256', $path), 'manifest' => $manifest]);

            return redirect()->route('tenant.tenant-backups', ['tenant_id' => $tenant->id]);
        } catch (Throwable $error) {
            File::delete($path);
            report($error);

            return back()->withErrors(['backup' => 'تعذر فحص النسخة: '.$this->publicError($error)]);
        }
    }

    public function restore(RestoreTenantBackupRequest $request, Tenant $tenant, TenantBackupService $backups): RedirectResponse
    {
        $preview = $request->session()->get('tenant_restore_preview');
        abort_unless($preview && $preview['token'] === $request->validated('token') && $preview['tenant_id'] === $tenant->id && $preview['user_id'] === $request->user()->id && $preview['expires'] >= time(), 422, 'انتهت صلاحية المعاينة. ارفع النسخة مجدداً.');
        $path = $backups->directory($tenant->id).'/incoming/'.$preview['token'].'.zip';
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        abort_unless(is_string($hash) && hash_equals($preview['sha256'], $hash), 422, 'تغير ملف النسخة. ارفعه مجدداً.');
        try {
            $safety = $backups->restore($path, $tenant);
            $request->session()->forget('tenant_restore_preview');
            File::delete($path);
            if (! $request->user()->isSaaSAdmin()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', 'تمت استعادة المتجر. سجل الدخول بحساب من النسخة المستعادة. حُفظت نسخة أمان قبل الاستعادة.');
            }

            return redirect()->route('tenant.tenant-backups', ['tenant_id' => $tenant->id])->with('status', 'تمت استعادة المتجر. نسخة الأمان قبل الاستعادة متاحة في القائمة: '.$safety['id']);
        } catch (Throwable $error) {
            report($error);

            return back()->withErrors(['backup' => 'أُلغيت الاستعادة ولم تتغير البيانات: '.$this->publicError($error)]);
        }
    }

    private function publicError(Throwable $error): string
    {
        return $error instanceof RuntimeException && ! $error instanceof QueryException
            ? $error->getMessage() : 'راجع مسؤول النظام لمعرفة سبب الخطأ.';
    }

    private function authorizeTenant(Request $request, Tenant $tenant, string $permission): void
    {
        $user = $request->user();
        abort_unless($user->isSaaSAdmin() || ($user->isTenantOwner() && $user->tenant_id === $tenant->id), 403);
        Gate::authorize($permission);
    }
}
