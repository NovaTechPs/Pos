<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('model changes across the application are recorded without secrets', function () {
    $user = User::factory()->create();

    $createdLog = AuditLog::query()
        ->where('subject_type', User::class)
        ->where('subject_id', $user->id)
        ->where('action', 'created')
        ->firstOrFail();

    expect($createdLog->new_values)->not->toHaveKey('password');

    $user->update(['name' => 'Updated User']);

    $updatedLog = AuditLog::query()
        ->where('subject_type', User::class)
        ->where('subject_id', $user->id)
        ->where('action', 'updated')
        ->firstOrFail();

    expect($updatedLog->old_values['name'])->not->toBe('Updated User')
        ->and($updatedLog->new_values['name'])->toBe('Updated User');
});

test('audit registration records soft deletion and restoration exactly once', function (): void {
    $tenant = Tenant::query()->create(['name' => 'سجل التدقيق']);
    $product = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'صنف', 'cost_price' => 1]);
    $product->delete();
    $product->restore();
    expect(AuditLog::query()->where('subject_type', $product::class)->where('subject_id', $product->id)->where('action', 'created')->count())->toBe(1)
        ->and(AuditLog::query()->where('subject_type', $product::class)->where('subject_id', $product->id)->where('action', 'deleted')->count())->toBe(1)
        ->and(AuditLog::query()->where('subject_type', $product::class)->where('subject_id', $product->id)->where('action', 'restored')->count())->toBe(1);
});
