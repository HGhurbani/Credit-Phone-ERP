<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Auth Routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'tenant.access'])->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    Route::middleware('permission:dashboard.view')->get('/dashboard', [DashboardController::class, 'index']);

    // Customers
    Route::middleware('permission:customers.view')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    });
    Route::middleware('permission:customers.create')->post('/customers', [CustomerController::class, 'store']);
    Route::middleware('permission:customers.update')->group(function () {
        Route::match(['put', 'patch'], '/customers/{customer}', [CustomerController::class, 'update']);
        Route::post('/customers/{customer}/notes', [CustomerController::class, 'addNote']);
    });
    Route::middleware('permission:customers.delete')->delete('/customers/{customer}', [CustomerController::class, 'destroy']);

    // Products
    Route::middleware('permission:products.view')->group(function () {
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
    });
    Route::middleware('permission:products.create')->post('/products', [ProductController::class, 'store']);
    Route::middleware('permission:products.update')->match(['put', 'patch'], '/products/{product}', [ProductController::class, 'update']);
    Route::middleware('permission:products.delete')->delete('/products/{product}', [ProductController::class, 'destroy']);
    Route::middleware('permission:products.stock.adjust')->post('/products/{product}/stock', [ProductController::class, 'adjustStock']);

    // Categories
    Route::middleware('permission:categories.view')->get('/categories', [CategoryController::class, 'index']);
    Route::middleware('permission:categories.create')->post('/categories', [CategoryController::class, 'store']);
    Route::middleware('permission:categories.update')->match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update']);
    Route::middleware('permission:categories.delete')->delete('/categories/{category}', [CategoryController::class, 'destroy']);

    // Brands
    Route::middleware('permission:brands.view')->get('/brands', [BrandController::class, 'index']);
    Route::middleware('permission:brands.create')->post('/brands', [BrandController::class, 'store']);
    Route::middleware('permission:brands.update')->match(['put', 'patch'], '/brands/{brand}', [BrandController::class, 'update']);
    Route::middleware('permission:brands.delete')->delete('/brands/{brand}', [BrandController::class, 'destroy']);

    // Orders
    Route::middleware('permission:orders.view')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
    });
    Route::middleware('permission:orders.create')->post('/orders', [OrderController::class, 'store']);
    Route::middleware('permission:orders.delete')->delete('/orders/{order}', [OrderController::class, 'destroy']);
    Route::middleware('permission:orders.approve')->post('/orders/{order}/approve', [OrderController::class, 'approve']);
    Route::middleware('permission:orders.reject')->post('/orders/{order}/reject', [OrderController::class, 'reject']);

    // Contracts
    Route::middleware('permission:contracts.view')->group(function () {
        Route::get('/contracts', [ContractController::class, 'index']);
        Route::get('/contracts/{contract}', [ContractController::class, 'show']);
        Route::get('/contracts/{contract}/schedules', [ContractController::class, 'schedules']);
    });
    Route::middleware('permission:contracts.create')->post('/contracts', [ContractController::class, 'store']);

    // Payments & Collections
    Route::middleware('permission:payments.view')->group(function () {
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    });
    Route::middleware('permission:payments.create')->post('/payments', [PaymentController::class, 'store']);
    Route::middleware('permission:payments.collections')->group(function () {
        Route::get('/collections/due-today', [PaymentController::class, 'dueToday']);
        Route::get('/collections/overdue', [PaymentController::class, 'overdue']);
    });

    // Invoices
    Route::middleware('permission:invoices.view')->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    });
    Route::middleware('permission:invoices.record_payment')->post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment']);
    Route::middleware('permission:invoices.update')->patch('/invoices/{invoice}', [InvoiceController::class, 'update']);

    // Users
    Route::middleware('permission:users.view')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
    });
    Route::middleware('permission:users.create')->post('/users', [UserController::class, 'store']);
    Route::middleware('permission:users.update')->match(['put', 'patch'], '/users/{user}', [UserController::class, 'update']);
    Route::middleware('permission:users.delete')->delete('/users/{user}', [UserController::class, 'destroy']);

    // Branches
    Route::middleware('permission:branches.view')->group(function () {
        Route::get('/branches', [BranchController::class, 'index']);
        Route::get('/branches/{branch}', [BranchController::class, 'show']);
    });
    Route::middleware('permission:branches.create')->post('/branches', [BranchController::class, 'store']);
    Route::middleware('permission:branches.update')->match(['put', 'patch'], '/branches/{branch}', [BranchController::class, 'update']);
    Route::middleware('permission:branches.delete')->delete('/branches/{branch}', [BranchController::class, 'destroy']);

    // Reports — view or export (accountant has both; branch manager has view only)
    Route::prefix('reports')->middleware('permission:reports.view|reports.export')->group(function () {
        Route::get('/sales', [ReportController::class, 'sales']);
        Route::get('/collections', [ReportController::class, 'collections']);
        Route::get('/active-contracts', [ReportController::class, 'activeContracts']);
        Route::get('/overdue-installments', [ReportController::class, 'overdueInstallments']);
        Route::get('/branch-performance', [ReportController::class, 'branchPerformance']);
        Route::get('/agent-performance', [ReportController::class, 'agentPerformance']);
    });

    // Settings
    Route::middleware('permission:settings.view')->get('/settings', [SettingController::class, 'index']);
    Route::middleware('permission:settings.update')->put('/settings', [SettingController::class, 'update']);

    // Roles (assignable list for user forms)
    Route::middleware('permission:roles.view')->get('/roles', [RoleController::class, 'index']);
});
