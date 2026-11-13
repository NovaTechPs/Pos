<?php

use Illuminate\Support\Facades\Schema;

test('the frequent foreign-key lookups have indexes', function (): void {
    $indexed = fn (string $table, array $columns): bool => collect(Schema::getIndexes($table))
        ->contains(fn (array $index): bool => $index['columns'] === $columns);

    expect($indexed('payments', ['tenant_id', 'order_id']))->toBeTrue()
        ->and($indexed('payments', ['tenant_id', 'purchase_id']))->toBeTrue()
        ->and($indexed('payments', ['tenant_id', 'purchase_return_id']))->toBeTrue()
        ->and($indexed('payments', ['tenant_id', 'created_by']))->toBeTrue()
        ->and($indexed('orders', ['tenant_id', 'created_by', 'created_at']))->toBeTrue()
        ->and($indexed('purchase_returns', ['tenant_id', 'supplier_id']))->toBeTrue()
        ->and($indexed('parties', ['tenant_id', 'branch_id']))->toBeTrue()
        ->and($indexed('users', ['tenant_id', 'role_id']))->toBeTrue()
        ->and($indexed('employees', ['tenant_id', 'branch_id']))->toBeTrue()
        ->and($indexed('stock_voucher_items', ['tenant_id', 'product_id']))->toBeTrue();
});

test('the production image tunes OPcache and PHP workers and caches built assets', function (): void {
    $dockerfile = file_get_contents(base_path('Dockerfile'));
    $opcache = file_get_contents(base_path('docker/opcache.ini'));
    $pool = file_get_contents(base_path('docker/php-fpm-pool.conf'));
    $nginx = file_get_contents(base_path('nginx.conf'));

    expect($dockerfile)->toContain('COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini', 'COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf', 'PHP_FPM_MAX_CHILDREN=10')
        ->and($opcache)->toContain('opcache.enable=1', 'opcache.validate_timestamps=0', 'opcache.max_accelerated_files=20000')
        ->and($pool)->toContain('[www]', 'pm = ondemand', 'pm.max_children = ${PHP_FPM_MAX_CHILDREN}')
        ->and($nginx)->toContain('location ^~ /build/', 'immutable')
        // The asset location must come before the PHP block so PHP requests keep their route.
        ->and(strpos($nginx, 'location ^~ /build/'))->toBeLessThan(strpos($nginx, 'location / {'));
});
