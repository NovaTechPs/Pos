<?php

test('browser tab includes the page name and WaslaPOS brand in English', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<title>Login - WaslaPOS</title>', false)
        ->assertSee('favicon.ico?v=waslapos-1', false)
        ->assertSee('favicon.svg?v=waslapos-1', false);
});

test('browser tab uses the Arabic page name when Arabic is active', function (): void {
    app()->setLocale('ar');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<title>تسجيل الدخول - WaslaPOS</title>', false);
});
