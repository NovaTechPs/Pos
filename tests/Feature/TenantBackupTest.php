<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Employee;
use App\Models\EmployeePayroll;
use App\Models\Party;
use App\Models\PayrollPeriod;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantBackupService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->originalStorage = storage_path();
    $this->backupStorage = storage_path('framework/testing/tenant-backup-'.Str::uuid());
    File::ensureDirectoryExists($this->backupStorage);
    app()->useStoragePath($this->backupStorage);
    Storage::fake('public');
    $this->tenant = Tenant::create(['name' => 'Backup shop', 'is_active' => true]);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->tenant->update(['owner_id' => $this->owner->id]);
    $this->branch->update(['created_by' => $this->owner->id]);
    $this->other = Tenant::create(['name' => 'Other shop', 'is_active' => true]);
    $this->otherUser = User::factory()->create(['tenant_id' => $this->other->id, 'is_owner' => true]);
    Storage::disk('public')->put('products/shared.png', 'original image bytes');
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Backup product', 'cost_price' => 5, 'image' => 'products/shared.png']);
    $this->stock = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 10, 'retail_price' => 20]);
    $this->otherProduct = Product::create(['tenant_id' => $this->other->id, 'name' => 'Private other product', 'cost_price' => 8, 'image' => 'products/shared.png']);
    $this->service = app(TenantBackupService::class);
});

afterEach(function (): void {
    app()->useStoragePath($this->originalStorage);
    if (str_starts_with($this->backupStorage, $this->originalStorage.DIRECTORY_SEPARATOR.'framework/testing/tenant-backup-') || str_contains($this->backupStorage, 'framework/testing/tenant-backup-')) {
        File::deleteDirectory($this->backupStorage);
    }
});

test('tenant archive is encrypted isolated and includes indirect children and soft deleted rows', function (): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'Deleted customer', 'type' => 'customer']);
    $party->delete();
    $purchase = DB::table('purchases')->insertGetId(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'reference_number' => 'BK-P1', 'total' => 5]);
    DB::table('purchase_items')->insert(['purchase_id' => $purchase, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 5, 'subtotal' => 5]);
    $manifest = $this->service->create($this->tenant);
    $path = $this->service->path($this->tenant->id, $manifest['id']);
    $verified = $this->service->inspect($path, $this->tenant);
    expect($verified['tables']['products']['count'])->toBe(1)->and($verified['tables']['parties']['count'])->toBe(1)
        ->and($verified['tables']['purchase_items']['count'])->toBe(1)->and($verified['files'])->toHaveCount(1);
    $zip = new ZipArchive;
    $zip->open($path);
    expect(@$zip->getFromName('manifest.json'))->toBeFalse();
    $zip->close();
    expect(fn () => $this->service->inspect($path, $this->other))->toThrow(RuntimeException::class);
});

test('restore replaces only this store preserves ledger and files and creates a safety copy', function (): void {
    $manifest = $this->service->create($this->tenant);
    $path = $this->service->path($this->tenant->id, $manifest['id']);
    $otherBefore = (array) DB::table('products')->where('id', $this->otherProduct->id)->first();
    $ledgerBefore = DB::table('stock_movements')->where('tenant_id', $this->tenant->id)->get()->toJson();
    $this->product->update(['name' => 'Changed after backup', 'cost_price' => 99]);
    $this->stock->decrement('stock_quantity', 3);
    Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Later product']);
    $this->owner->createToken('mobile-app');
    $safety = $this->service->restore($path, $this->tenant);
    expect($safety['kind'])->toBe('before-restore')->and($safety['tables']['products']['count'])->toBe(2)
        ->and(DB::table('products')->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(DB::table('products')->where('id', $this->product->id)->value('name'))->toBe('Backup product')
        ->and((float) DB::table('branch_products')->where('id', $this->stock->id)->value('stock_quantity'))->toBe(10.0)
        ->and(DB::table('stock_movements')->where('tenant_id', $this->tenant->id)->get()->toJson())->toBe($ledgerBefore)
        ->and((array) DB::table('products')->where('id', $this->otherProduct->id)->first())->toBe($otherBefore)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(DB::table('tenants')->where('id', $this->tenant->id)->value('owner_id'))->toBe($this->owner->id);
    $image = DB::table('products')->where('id', $this->product->id)->value('image');
    expect($image)->toStartWith('tenant-restores/'.$this->tenant->id.'/');
    expect(Storage::disk('public')->get($image))->toBe('original image bytes')->and(Storage::disk('public')->get('products/shared.png'))->toBe('original image bytes');
    expect($this->service->history($this->tenant))->toHaveCount(2);
    $this->stock->increment('stock_quantity', 1);
    expect(DB::table('stock_movements')->where('tenant_id', $this->tenant->id)->count())->toBe(2);
    expect(fn () => DB::table('stock_movements')->where('tenant_id', $this->tenant->id)->delete())->toThrow(QueryException::class);
});

test('mid restore failure rolls back all database changes and reinstates immutable triggers', function (): void {
    $manifest = $this->service->create($this->tenant);
    $oldEmail = $this->owner->email;
    $this->owner->update(['email' => 'changed-backup-owner@example.test']);
    $this->otherUser->update(['email' => $oldEmail]);
    $this->stock->decrement('stock_quantity', 1);
    $before = DB::table('stock_movements')->get()->toJson();
    expect(fn () => $this->service->restore($this->service->path($this->tenant->id, $manifest['id']), $this->tenant))->toThrow(QueryException::class);
    expect(DB::table('users')->where('id', $this->owner->id)->value('email'))->toBe('changed-backup-owner@example.test')
        ->and(DB::table('users')->where('id', $this->otherUser->id)->value('email'))->toBe($oldEmail)
        ->and(DB::table('stock_movements')->get()->toJson())->toBe($before);
    expect(fn () => DB::table('stock_movements')->delete())->toThrow(QueryException::class);
});

test('schema mismatch and tampered archives are rejected without changing data', function (): void {
    $manifest = $this->service->create($this->tenant);
    $path = $this->service->path($this->tenant->id, $manifest['id']);
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->setPassword(hash_hmac('sha256', 'waslapos-tenant-backup-v1', (string) config('app.key')));
    $zip->deleteName('signature');
    $zip->addFromString('signature', 'forged signature');
    $zip->close();
    expect(fn () => $this->service->restore($path, $this->tenant))->toThrow(RuntimeException::class);
    expect(Product::withoutGlobalScopes()->count())->toBe(2);
});

test('owners can access store backups but cannot download another store backup', function (): void {
    $this->actingAs($this->owner)->get(route('tenant.tenant-backups'))->assertOk()->assertSee('Backup shop')->assertDontSee('Other shop');
    $otherManifest = $this->service->create($this->other);
    $this->get(route('tenant.tenant-backups.download', ['tenant' => $this->other, 'backup' => $otherManifest['id']]))->assertForbidden();
    $this->post(route('tenant.tenant-backups.create', $this->other))->assertForbidden();
});

test('staff cannot access restore even with a forged tenant parameter', function (): void {
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => false]);
    $this->actingAs($staff)->post(route('tenant.tenant-backups.restore', $this->tenant), ['token' => Str::uuid(), 'confirmation' => $this->tenant->name, 'acknowledge' => 1])->assertForbidden();
});

test('upload preview is read only and confirmation is required before restoring', function (): void {
    $manifest = $this->service->create($this->tenant);
    $path = $this->service->path($this->tenant->id, $manifest['id']);
    $this->product->update(['name' => 'Current version']);
    $file = new UploadedFile($path, 'backup.zip', 'application/zip', null, true);
    $this->actingAs($this->owner)->post(route('tenant.tenant-backups.preview', $this->tenant), ['backup' => $file])->assertRedirect()->assertSessionHas('tenant_restore_preview');
    expect(DB::table('products')->where('id', $this->product->id)->value('name'))->toBe('Current version');
    $token = session('tenant_restore_preview.token');
    $this->post(route('tenant.tenant-backups.restore', $this->tenant), ['token' => $token, 'confirmation' => 'wrong', 'acknowledge' => 1])->assertSessionHasErrors('confirmation');
    expect(DB::table('products')->where('id', $this->product->id)->value('name'))->toBe('Current version');
});

test('owners can create and download their archive and administrators can restore after preview', function (): void {
    $this->actingAs($this->owner)->post(route('tenant.tenant-backups.create', $this->tenant))->assertDownload();
    $manifest = $this->service->history($this->tenant)[0];
    $this->get(route('tenant.tenant-backups.download', ['tenant' => $this->tenant, 'backup' => $manifest['id']]))->assertDownload();
    $admin = User::factory()->create(['type' => 'saas_admin', 'tenant_id' => null, 'is_owner' => false]);
    $this->product->update(['name' => 'Changed before HTTP restore']);
    $copy = $this->backupStorage.'/upload-copy.zip';
    copy($this->service->path($this->tenant->id, $manifest['id']), $copy);
    $this->actingAs($admin)->post(route('tenant.tenant-backups.preview', $this->tenant), ['backup' => new UploadedFile($copy, 'backup.zip', 'application/zip', null, true)])->assertRedirect()->assertSessionHas('tenant_restore_preview');
    $this->post(route('tenant.tenant-backups.restore', $this->tenant), ['token' => session('tenant_restore_preview.token'), 'confirmation' => $this->tenant->name, 'acknowledge' => 1])->assertRedirect(route('tenant.tenant-backups', ['tenant_id' => $this->tenant->id]))->assertSessionHasNoErrors();
    expect(DB::table('products')->where('id', $this->product->id)->value('name'))->toBe('Backup product');
});

test('approved payroll and dependent items survive restore with their protection reinstated', function (): void {
    $employee = Employee::create(['tenant_id' => $this->tenant->id, 'employee_code' => 'BACKUP-EMP', 'first_name' => 'Test', 'last_name' => 'Employee', 'hire_date' => '2026-01-01', 'basic_salary' => 1000]);
    $period = PayrollPeriod::create(['tenant_id' => $this->tenant->id, 'title' => 'Backup payroll', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'approved']);
    $payroll = EmployeePayroll::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'net_salary' => 1000]);
    DB::table('employee_payroll_items')->insert(['employee_payroll_id' => $payroll->id, 'type' => 'addition', 'title' => 'Bonus', 'amount' => 20]);
    $manifest = $this->service->create($this->tenant);
    $this->service->restore($this->service->path($this->tenant->id, $manifest['id']), $this->tenant);
    expect(DB::table('employee_payroll_items')->where('employee_payroll_id', $payroll->id)->value('amount'))->toEqual(20);
    expect(fn () => DB::table('employee_payrolls')->where('id', $payroll->id)->delete())->toThrow(QueryException::class);
});

test('an archived ID now owned by another tenant is rejected without affecting either store', function (): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'Original party', 'type' => 'customer']);
    $manifest = $this->service->create($this->tenant);
    DB::table('parties')->where('id', $party->id)->delete();
    DB::table('parties')->insert(['id' => $party->id, 'tenant_id' => $this->other->id, 'name' => 'Another store party', 'type' => 'customer']);
    expect(fn () => $this->service->restore($this->service->path($this->tenant->id, $manifest['id']), $this->tenant))->toThrow(RuntimeException::class);
    expect(DB::table('parties')->where('id', $party->id)->value('tenant_id'))->toBe($this->other->id);
});
