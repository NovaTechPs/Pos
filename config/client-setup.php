<?php

return [
    'password' => env('CLIENT_SEED_PASSWORD'),
    'tenant_name' => env('CLIENT_TENANT_NAME', 'fanous'),
    'plan_slug' => 'plan',
    'branches' => ['فانوس', 'crase', 'مخزن جملة'],
    'users' => [
        ['name' => 'SaaS Admin', 'email' => env('CLIENT_ADMIN_EMAIL', 'admin@example.com'), 'role' => 'system_admin', 'branch' => null],
        ['name' => 'مالك المتجر', 'email' => env('CLIENT_OWNER_EMAIL', 'user@example.com'), 'role' => 'owner', 'branch' => 0],
        ['name' => 'ياسمين', 'email' => 'yasmin@fanos.com', 'role' => 'admin', 'branch' => 0],
        ['name' => 'خالد', 'email' => 'kalide@fanous.com', 'role' => 'admin', 'branch' => 1],
        ['name' => 'هادي', 'email' => 'hade@fanous.com', 'role' => 'wholesale_employee', 'branch' => 2],
    ],
];
