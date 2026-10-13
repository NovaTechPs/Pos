<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Observers\AuditObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        foreach (File::allFiles(app_path('Models')) as $modelFile) {
            $modelPath = str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $modelFile->getRelativePathname());
            $modelClass = 'App\\Models\\'.$modelPath;

            if (
                class_exists($modelClass)
                && is_subclass_of($modelClass, Model::class)
                && $modelClass !== AuditLog::class
            ) {
                $modelClass::observe(AuditObserver::class);
            }
        }

        view()->composer('*', function () {
            if (Session::has('locale')) {
                App::setLocale(Session::get('locale'));
            }
        });

        $this->configureDefaults();

        if (config('app.env') !== 'local' || request()->header('X-Forwarded-Proto') === 'https') {
            URL::forceScheme('https');
        }

        Schema::defaultStringLength(191);

        // فحص الصلاحيات بشكل ديناميكي
        Gate::before(function (User $user, string $ability) {
            return $user->hasPermission($ability) ? true : null;
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
