<?php

use App\Models\AuditLog;
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
