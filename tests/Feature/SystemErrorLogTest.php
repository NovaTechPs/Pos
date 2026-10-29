<?php

use App\Models\User;
use App\Services\SystemErrorLog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

beforeEach(function (): void {
    $this->originalStorage = storage_path();
    $this->logStorage = sys_get_temp_dir().'/wasla-log-tests-'.bin2hex(random_bytes(8));
    mkdir($this->logStorage.'/logs', 0700, true);
    app()->useStoragePath($this->logStorage);
    $this->logs = app(SystemErrorLog::class);
});

afterEach(function (): void {
    app()->useStoragePath($this->originalStorage);
    File::deleteDirectory($this->logStorage);
});

test('guests and tenant owners cannot read global application logs', function (): void {
    $this->get('/admin/system-errors')->assertRedirect(route('login'));
    $owner = User::factory()->create(['type' => 'tenant_user', 'is_owner' => true]);
    $this->actingAs($owner)->get('/admin/system-errors')->assertRedirect(route('tenant.dashboard'));
});

test('platform admin sees safe errors newest first without credentials or customer data', function (): void {
    file_put_contents($this->logStorage.'/logs/laravel.log', <<<'LOG'
[2026-10-29 07:49:35] production.ERROR: SQLSTATE[42703]: column missing customer@example.com password=secret-value Bearer secret-token {"exception":"[object] (Illuminate\\Database\\QueryException(code: 42703) at /var/www/app/Http/Controllers/Api/PosController.php:450)","customer":"Private Customer","sql":"insert into orders values (private-data)"}
[2026-10-29 07:50:35] production.ERROR: TypeError at /var/www/app/Services/ApiShiftLedger.php:20 <script>alert('private')</script>
LOG);
    $admin = User::factory()->create(['type' => 'saas_admin']);
    $this->actingAs($admin)->get('/admin/system-errors')->assertOk()
        ->assertSee('أخطاء النظام')->assertSeeInOrder(['07:50:35', '07:49:35'])
        ->assertSee('SQLSTATE[42703]')->assertSee('app/Http/Controllers/Api/PosController.php:450')
        ->assertDontSee('secret-value')->assertDontSee('secret-token')
        ->assertDontSee('customer@example.com')->assertDontSee('Private Customer')
        ->assertDontSee('private-data')->assertDontSee('/var/www')->assertDontSee('<script>alert', false);
    expect($this->get('/admin/system-errors')->headers->get('Cache-Control'))->toContain('no-store');
});

test('unknown file names and path traversal are rejected', function (): void {
    $admin = User::factory()->create(['type' => 'saas_admin']);
    $this->actingAs($admin)->getJson('/admin/system-errors?file=../../.env')->assertUnprocessable();
    $this->getJson('/admin/system-errors?file=browser.log')->assertUnprocessable();
    expect($this->logs->entries('../../.env'))->toBe([]);
});

test('only expected regular log names are listed and missing files render safely', function (): void {
    foreach (['laravel.log', 'diagnostics-2026-10-29.log', 'browser.log', 'laravel-secret.log', '.env'] as $name) {
        file_put_contents($this->logStorage.'/logs/'.$name, '');
    }
    expect($this->logs->files())->toHaveCount(2)
        ->toContain('laravel.log', 'diagnostics-2026-10-29.log');
    $admin = User::factory()->create(['type' => 'saas_admin']);
    $this->actingAs($admin)->get('/admin/system-errors')->assertOk()->assertSee('لا توجد أخطاء');
});

test('bounded tail returns at most fifty errors and supports warnings and daily files', function (): void {
    $text = str_repeat('old-sensitive-data', 20000)."\n";
    for ($i = 0; $i < 70; $i++) {
        $text .= '[2026-10-29 07:49:'.sprintf('%02d', $i % 60).'] production.ERROR: RuntimeException at /var/www/app/Services/Example.php:'.$i."\n";
    }
    $text .= "[2026-10-29 07:50:00] production.WARNING: RuntimeException\n";
    file_put_contents($this->logStorage.'/logs/laravel-2026-10-29.log', $text);
    $entries = $this->logs->entries('laravel-2026-10-29.log');
    expect($entries)->toHaveCount(50)->and($entries[0]['details'])->toContain('Example.php:69');
    expect($this->logs->entries('laravel-2026-10-29.log', 'warning')[0]['level'])->toBe('WARNING');
});

test('diagnostic reporting excludes arbitrary messages and retains code locations', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    Log::shouldReceive('channel')->once()->with('diagnostics')->andReturn($logger);
    $logger->shouldReceive('error')->once()->withArgs(fn (string $message): bool => str_contains($message, 'RuntimeException') && ! str_contains($message, 'private-password'));
    $this->logs->record(new RuntimeException('private-password customer@example.com'));
    expect($this->logs->safeDetails('password=SecretException token=HiddenError'))
        ->not->toContain('SecretException', 'HiddenError');
    $safe = $this->logs->safeDetails('SQLSTATE[23514]: new row violates check constraint "payments_amount_check" DETAIL: Failing row contains (private-password)');
    expect($safe)->toContain('constraint=payments_amount_check')->not->toContain('private-password');
    expect($this->logs->safeDetails($safe))->toContain('constraint=payments_amount_check');
    expect($this->logs->safeDetails('TypeError at C:\\app\\Services\\Example.php:42'))
        ->toContain('app/Services/Example.php:42');
});

test('diagnostic storage failure never replaces the original exception', function (): void {
    Log::shouldReceive('channel')->once()->andThrow(new RuntimeException('disk full'));
    $this->logs->record(new RuntimeException('original failure'));
    expect(true)->toBeTrue();
});

test('reported exceptions reach the daily diagnostic file even when the main log uses stderr', function (): void {
    config(['logging.channels.diagnostics.path' => $this->logStorage.'/logs/diagnostics.log']);
    report(new RuntimeException('private-password customer@example.com'));
    $files = $this->logs->files();
    expect($files)->toHaveCount(1)->and($files[0])->toStartWith('diagnostics-');
    $entries = $this->logs->entries($files[0]);
    expect($entries)->toHaveCount(1)->and($entries[0]['details'])->toContain('RuntimeException');
    expect(file_get_contents($this->logStorage.'/logs/'.$files[0]))
        ->not->toContain('private-password', 'customer@example.com');
});
