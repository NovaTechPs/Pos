<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BranchSyncOperationController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\EmployeeApiController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\PartyStatementController;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\ThemeController;
use App\Http\Controllers\Api\VoucherController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('api.login');

Route::middleware(['auth:sanctum', 'api.tenant'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/theme', [ThemeController::class, 'show'])->name('api.theme');
    Route::get('/branches', [BranchController::class, 'index'])->middleware('can:branches.view');

    Route::get('/pos/products', [PosController::class, 'index'])->middleware('can:pos.view');
    Route::get('/wholesale/products', [PosController::class, 'wholesaleProducts'])->middleware('can:wholesale_sales.view');
    Route::post('/wholesale/orders', [PosController::class, 'storeWholesaleOrder'])->middleware('can:wholesale_sales.create');
    Route::get('/wholesale/orders', [PosController::class, 'wholesaleOrders'])->middleware('can:wholesale_sales.view');
    Route::get('/sales/orders', [PosController::class, 'salesOrders'])->middleware('can:sales_invoices.view');
    Route::post('/pos/orders', [PosController::class, 'storeOrder'])->middleware('can:pos.sale.checkout');
    Route::get('/pos/held', [PosController::class, 'held'])->middleware('can:pos.invoice.search');
    Route::post('/pos/held', [PosController::class, 'hold'])->middleware('can:pos.invoice.hold');
    Route::delete('/pos/held/{order}', [PosController::class, 'deleteHeld'])->middleware('can:pos.invoice.hold');

    Route::get('/shifts/active', [ShiftController::class, 'active'])->middleware('can:pos.view');
    Route::post('/shifts/open', [ShiftController::class, 'open'])->middleware('can:pos.shift.open');
    Route::post('/shifts/close', [ShiftController::class, 'close'])->middleware('can:pos.shift.close');

    Route::get('/customers', [CustomerController::class, 'index'])->middleware('can:customers.view');
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('can:customers.create');
    Route::get('/suppliers', [CustomerController::class, 'suppliers'])->middleware('can:suppliers.view');
    Route::post('/suppliers', [CustomerController::class, 'storeSupplier'])->middleware('can:suppliers.create');
    Route::get('/parties/{party}/statement', [PartyStatementController::class, 'show']);

    Route::get('/orders/invoice/{invoiceNumber}', [ReturnController::class, 'findInvoice'])->middleware('can:returns.view');
    Route::post('/orders/returns', [ReturnController::class, 'storeReturn'])->middleware('can:returns.create');

    Route::get('/expenses', [ExpenseController::class, 'index'])->middleware('can:accounting.voucher.view');
    Route::post('/expenses', [ExpenseController::class, 'store'])->middleware('can:accounting.voucher.create');
    Route::post('/vouchers/receipts', [VoucherController::class, 'storeReceipt'])->middleware('can:accounting.receipt.create');
    Route::get('/vouchers', [VoucherController::class, 'index']);
    Route::post('/vouchers/payments', [VoucherController::class, 'storePayment'])->middleware('can:accounting.payments.create');

    Route::get('/stock/transfers', [StockController::class, 'transfers'])->middleware('can:stock_transfers.view');
    Route::post('/stock/transfers', [StockController::class, 'storeTransfer'])->middleware('can:stock_transfers.create');

    Route::get('/reports/summary', [ReportController::class, 'summary'])->middleware('can:reports.analytics');

    Route::get('/employee/profile', [EmployeeApiController::class, 'profile']);
    Route::get('/employees', [EmployeeApiController::class, 'index'])->middleware('can:employees.view');
    Route::get('/employee/attendance', [EmployeeApiController::class, 'attendanceIndex'])->middleware('can:employees.view');
    Route::post('/employees', [EmployeeApiController::class, 'store'])->middleware('can:employees.create');
    Route::patch('/employees/{employee}', [EmployeeApiController::class, 'update'])->middleware('can:employees.edit');
    Route::get('/employee/advances/requests', [EmployeeApiController::class, 'advanceRequests'])->middleware('can:employees.advances.view');
    Route::patch('/employee/advances/{advance}', [EmployeeApiController::class, 'updateAdvance'])->middleware('can:employees.advances.manage');
    Route::post('/employee/advances/{advance}/pay', [EmployeeApiController::class, 'payAdvance'])->middleware('can:employees.advances.manage');
    Route::post('/employee/attendance/clock', [EmployeeApiController::class, 'clock']);
    Route::post('/employee/attendance/scan', [EmployeeApiController::class, 'scanAttendance'])->middleware(['can:employees.view', 'throttle:30,1']);
    Route::get('/employee/finances', [EmployeeApiController::class, 'finances']);
    Route::post('/employee/advances', [EmployeeApiController::class, 'requestAdvance']);
    Route::post('/employee/leaves', [EmployeeApiController::class, 'requestLeave']);
});

Route::middleware(['branch.sync', 'throttle:120,1'])
    ->post('/branch-sync/operations', [BranchSyncOperationController::class, 'store'])
    ->name('api.branch-sync.operations.store');
