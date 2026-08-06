<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\URL;

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
    public function boot(): void
    {
        $this->configureDefaults();
        if (config('app.env') !== 'local' || request()->header('X-Forwarded-Proto') === 'https') {
            URL::forceScheme('https');
        }
        Schema::defaultStringLength(191);
        // تعريف فحص ديناميكي لكافة الصلاحيات
        Gate::before(function (User $user, string $ability) {
            return $user->hasPermission($ability) ? true : null;
        });

        Gate::define('access-pos', function (User $user) {
            return $user->tenant && $user->tenant->hasFeature('pos_system');
        });

        // Gate للتحقق من إمكانية إضافة منتج جديد بناءً على الحد الأقصى للمتجر
        Gate::define('create-product', function (User $user) {
            $tenant = $user->tenant;
            if (!$tenant || !$tenant->hasFeature('products_limit')) {
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

        Password::defaults(fn (): ?Password => app()->isProduction()
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
