<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\File;

test('Arabic is the default language for new visitors with right to left layouts', function (): void {
    expect(config('app.locale'))->toBe('ar')
        ->and(config('app.fallback_locale'))->toBe('ar');

    $this->get(route('login'))->assertOk()
        ->assertSee('lang="ar"', false)
        ->assertSee('dir="rtl"', false)
        ->assertSee('تسجيل الدخول إلى حسابك')
        ->assertDontSee('Enter your email and password below to log in');

    $this->get(route('home'))->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee('إدارة ذكية للمبيعات والمخزون مع')
        ->assertDontSee('Rapid Checkout');
});

test('navigation labels including receipts are translated in Arabic', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر عربي']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);

    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id])
        ->get(route('tenant.dashboard'))->assertOk()
        ->assertSee('سندات القبض')
        ->assertSee('الطلبات الإلكترونية')
        ->assertSee('أسعار الصرف')
        ->assertDontSee('Receipts')
        ->assertDontSee('Online Orders');
});

test('authentication and validation errors use Arabic before rendering a view', function (): void {
    $this->post(route('login.store'), ['email' => '', 'password' => ''])
        ->assertSessionHasErrors(['email' => 'حقل البريد الإلكتروني مطلوب.', 'password' => 'حقل كلمة المرور مطلوب.']);

    $user = User::factory()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'بيانات الدخول غير صحيحة.']);
});

test('explicit English preference remains supported and invalid locales fall back to Arabic', function (): void {
    $this->withSession(['locale' => 'en'])->get(route('login'))
        ->assertOk()->assertSee('lang="en"', false)->assertSee('dir="ltr"', false)
        ->assertSee('Log in to your account');

    $this->withSession(['locale' => 'invalid'])->get(route('login'))
        ->assertOk()->assertSee('lang="ar"', false)->assertSee('تسجيل الدخول إلى حسابك');
});

test('all static English translation keys in application views have Arabic translations', function (): void {
    $translations = json_decode(File::get(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach (File::allFiles(resource_path('views')) as $file) {
        preg_match_all('/__\(\s*\x27((?:\\\\.|[^\x27\\\\])*)\x27\s*[,)]/', $file->getContents(), $matches);
        foreach ($matches[1] as $key) {
            $key = str_replace("\\'", "'", $key);
            if (preg_match('/[a-z]/i', $key) && ! preg_match('/^[a-z]+\.[a-z_]+$/', $key)) {
                expect($translations)->toHaveKey($key);
                expect($translations[$key])->toMatch('/[\x{0600}-\x{06ff}]/u');
            }
        }
    }
});
