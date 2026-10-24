<?php

test('browser tab includes the page name and WaslaPOS brand in English', function (): void {
    app()->setLocale('en');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<title>Login - WaslaPOS</title>', false)
        ->assertSee('favicon.ico?v=waslapos-1', false)
        ->assertSee('favicon.svg?v=waslapos-1', false);
});

test('new visitors see Arabic by default', function (): void {
    expect(config('app.locale'))->toBe('ar');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('lang="ar"', false)
        ->assertSee('<title>تسجيل الدخول - WaslaPOS</title>', false);
});

test('visitors can keep their selected English language', function (): void {
    $this->withSession(['locale' => 'en'])
        ->get(route('login'))
        ->assertOk()
        ->assertSee('lang="en"', false)
        ->assertSee('<title>Login - WaslaPOS</title>', false);
});

test('browser tab uses the Arabic page name when Arabic is active', function (): void {
    app()->setLocale('ar');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<title>تسجيل الدخول - WaslaPOS</title>', false);
});
