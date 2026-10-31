@props(['title' => null, 'shellMain' => false])

@if ((request()->header('Sec-Fetch-Dest') === 'iframe'))
    {{ $slot }}
@else
    <div data-workspace-tabs @if ($shellMain) data-flux-main @endif class="workspace-shell app-shell-main" data-home-url="{{ route(auth()->user()?->isSaaSAdmin() && ! session('active_tenant_id') ? 'admin.dashboard' : 'tenant.dashboard') }}" data-persistence-key="workspace-tabs:{{ auth()->id() }}:{{ session('active_tenant_id') }}:{{ session('active_branch_id') }}" data-initial-title="{{ $title ?? $pageTitle ?? config('app.name') }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
        <div class="workspace-toolbar">
            <details data-workspace-menu class="workspace-menu">
                <summary>☰ <span>فتح قسم</span></summary>
                <div class="workspace-menu-panel">
                    @include(auth()->user()?->isSaaSAdmin() && ! session('active_tenant_id') ? 'layouts.admin.navigation' : 'layouts.tenant.navigation')
                </div>
            </details>
            <div data-workspace-tab-list class="workspace-tab-list" role="tablist" aria-label="الصفحات المفتوحة"></div>
        </div>
        <div data-workspace-panels class="workspace-panels">
            <div data-workspace-loading class="workspace-loading" role="status" aria-live="polite" hidden>
                <span class="workspace-spinner" aria-hidden="true"></span>
                <span data-workspace-loading-text>جارٍ فتح الصفحة…</span>
                <button type="button" data-workspace-retry hidden>إعادة المحاولة</button>
            </div>
            <div data-workspace-initial class="workspace-initial" role="tabpanel">
                {{ $slot }}
            </div>
        </div>
    </div>
@endif
