<?php

return [
    'password' => env('CLIENT_SEED_PASSWORD'),
    'restore_file' => env('CLIENT_RESTORE_FILE') ?: storage_path('app/private/restore/WaslaPOS-FULL-RESTORE-20261031.sql'),
    'restore_sha256' => env('CLIENT_RESTORE_SHA256', '76b8b61b9d94b4bfec1f9ab464da9015b49f29b8f1c1f9d96f295f7c29f2edb4'),
    'tenant_name' => env('CLIENT_TENANT_NAME', 'fanous'),
    'plan_slug' => 'plan',
    'branches' => ['فانوس', 'craze', 'مخزن جملة'],
    'users' => [
        ['name' => 'SaaS Admin', 'email' => env('CLIENT_ADMIN_EMAIL', 'admin@example.com'), 'role' => 'system_admin', 'branch' => null],
        ['name' => 'مالك المتجر', 'email' => env('CLIENT_OWNER_EMAIL', 'user@example.com'), 'role' => 'owner', 'branch' => 0],
        ['name' => 'ياسمين', 'email' => 'yasmin@fanos.com', 'role' => 'admin', 'branch' => 0],
        ['name' => 'خالد', 'email' => 'kalide@craze.com', 'role' => 'admin', 'branch' => 1],
        ['name' => 'هادي', 'email' => 'hade@fanous.com', 'role' => 'wholesale_employee', 'branch' => 2],
    ],
];
