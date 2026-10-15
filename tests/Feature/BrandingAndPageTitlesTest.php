<?php

test('browser tab includes the page name and NOVO brand in English', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<title>Login - NOVO</title>', false)
        ->assertSee('favicon.ico?v=novo-2', false)
        ->assertSee('favicon.svg?v=novo-2', false);
});

test('browser tab uses the Arabic page name when Arabic is active', function (): void {
    app()->setLocale('ar');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<title>تسجيل الدخول - NOVO</title>', false);
});
