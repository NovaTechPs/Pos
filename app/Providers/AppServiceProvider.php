<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\BranchSyncOperation;
use App\Models\Product;
use App\Models\TenantSetting;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\TelegramNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Livewire\Component;
use ReflectionProperty;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    /**
     * Clearing a number field sends an empty value, which Livewire cannot store in a typed
     * property, so it unsets the property and the next read fails with «Property not found».
     * Such a property goes back to its default value (for example 0) instead.
     */
    private function restoreClearedLivewireNumbers(): void
    {
        \Livewire\before('update', function (Component $component, string $path, mixed $value): ?\Closure {
            if (($value !== '' && $value !== null) || str_contains($path, '.') || ! property_exists($component, $path)) {
                return null;
            }

            return function () use ($component, $path): void {
                $property = new ReflectionProperty($component, $path);
                if ($property->hasType() && $property->hasDefaultValue() && ! $property->isInitialized($component)) {
                    $property->setValue($component, $property->getDefaultValue());
                }
            };
        });
    }

    public function boot(): void
    {
        TelegramNotifier::listen();
        $this->restoreClearedLivewireNumbers();

        // App sign-ins unused for the configured number of days are refused (see auth.api_token_idle_days).
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            $idleDays = (int) config('auth.api_token_idle_days');
            $lastActivity = $token->last_used_at ?? $token->created_at;

            return $isValid && ($idleDays <= 0 || $lastActivity === null || $lastActivity->gte(now()->subDays($idleDays)));
        });

        foreach (['created', 'updated', 'deleted', 'restored'] as $event) {
            Event::listen('eloquent.'.$event.': App\\Models\\*', function (string $eventName, array $models) use ($event): void {
                $model = $models[0] ?? null;
                if ($model instanceof Model && ! $model instanceof AuditLog && ! $model instanceof BranchSyncOperation) {
                    app(AuditObserver::class)->{$event}($model);
                }
            });
        }

        view()->composer('*', function ($view): void {
            $theme = request()->attributes->get('app_theme');

            if (! is_string($theme)) {
                $tenantId = Session::get('active_tenant_id') ?? auth()->user()?->tenant_id;
                $storedTheme = $tenantId ? TenantSetting::get((int) $tenantId, 'ui_theme', 'blue') : 'blue';
                $theme = in_array($storedTheme, ['blue', 'orange', 'green'], true) ? $storedTheme : 'blue';
                request()->attributes->set('app_theme', $theme);
            }

            $view->with('appTheme', $theme);
        });

        $this->configureDefaults();

        if (! config('app.desktop') && (config('app.env') !== 'local' || request()->header('X-Forwarded-Proto') === 'https')) {
            URL::forceScheme('https');
        }

        Schema::defaultStringLength(191);

        // فحص الصلاحيات بشكل ديناميكي
        Gate::before(function (User $user, string $ability) {
            return $user->hasPermission($ability) ? true : null;
        });

        // تعديل أسعار البيع في فرع معيّن: فرع الموظف أو الفروع التي حددها له المالك
        Gate::define('edit-branch-price', function (User $user, ?int $branchId = null): bool {
            return $branchId === null ? $user->canEditAnyBranchPrice() : $user->canEditBranchPrice($branchId);
        });

        // مدير المهام: يفتحه من يدير المهام، أو الموظف المرتبط بحسابه ليرى مهامه
        Gate::define('tasks.access', function (User $user): bool {
            return $user->hasPermission('tasks.manage') || $user->employee()->exists();
        });

        // Gate للتحقق من إمكانية الوصول إلى نظام POS
        Gate::define('access-pos', function (User $user) {
            return $user->tenant && $user->tenant->hasFeature('pos_system');
        });

        // Gate للتحقق من إمكانية إضافة منتج جديد بناءً على الحد الأقصى للمتجر
        Gate::define('create-product', function (User $user) {
            $tenant = $user->tenant;

            if (! $tenant || ! $tenant->hasFeature('products_limit')) {
                return false;
            }

            $maxProducts = (int) $tenant->getLimit('products_limit', 0);

            // إذا كان الحد -1 أو unlimited نتيح الإضافة دائماً
            if ($maxProducts === -1) {
                return true;
            }

            $currentProductCount = Product::where('tenant_id', $tenant->id)->count();

            return $currentProductCount < $maxProducts;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
                ? Password::min(12)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                : null,
        );
    }
}
