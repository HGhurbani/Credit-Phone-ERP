<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
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

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Customers
    Route::apiResource('customers', CustomerController::class);
    Route::post('/customers/{customer}/notes', [CustomerController::class, 'addNote']);

    // Products
    Route::apiResource('products', ProductController::class);
    Route::post('/products/{product}/stock', [ProductController::class, 'adjustStock']);

    // Categories & Brands (simple CRUD via resource controllers or inline)
    Route::get('/categories', fn() => response()->json(['data' => \App\Models\Category::forTenant(request()->user()->tenant_id)->get()]));
    Route::post('/categories', function (\Illuminate\Http\Request $request) {
        $request->validate(['name' => 'required|string|max:255', 'name_ar' => 'nullable|string']);
        $cat = \App\Models\Category::create(['tenant_id' => $request->user()->tenant_id, ...$request->only('name', 'name_ar', 'description')]);
        return response()->json(['data' => $cat], 201);
    });

    Route::get('/brands', fn() => response()->json(['data' => \App\Models\Brand::forTenant(request()->user()->tenant_id)->get()]));
    Route::post('/brands', function (\Illuminate\Http\Request $request) {
        $request->validate(['name' => 'required|string|max:255']);
        $brand = \App\Models\Brand::create(['tenant_id' => $request->user()->tenant_id, ...$request->only('name')]);
        return response()->json(['data' => $brand], 201);
    });

    // Orders
    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::post('/orders/{order}/approve', [OrderController::class, 'approve']);
    Route::post('/orders/{order}/reject', [OrderController::class, 'reject']);

    // Contracts
    Route::apiResource('contracts', ContractController::class)->only(['index', 'store', 'show']);
    Route::get('/contracts/{contract}/schedules', [ContractController::class, 'schedules']);

    // Payments & Collections
    Route::apiResource('payments', PaymentController::class)->only(['index', 'store', 'show']);
    Route::get('/collections/due-today', [PaymentController::class, 'dueToday']);
    Route::get('/collections/overdue', [PaymentController::class, 'overdue']);

    // Invoices
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment']);
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update']);

    // Users
    Route::apiResource('users', UserController::class);

    // Branches
    Route::apiResource('branches', BranchController::class);

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/sales', [ReportController::class, 'sales']);
        Route::get('/collections', [ReportController::class, 'collections']);
        Route::get('/active-contracts', [ReportController::class, 'activeContracts']);
        Route::get('/overdue-installments', [ReportController::class, 'overdueInstallments']);
        Route::get('/branch-performance', [ReportController::class, 'branchPerformance']);
        Route::get('/agent-performance', [ReportController::class, 'agentPerformance']);
    });

    // Settings
    Route::get('/settings', function (\Illuminate\Http\Request $request) {
        $settings = \App\Models\Setting::where('tenant_id', $request->user()->tenant_id)->get()
            ->pluck('value', 'key');
        return response()->json(['data' => $settings]);
    });
    Route::put('/settings', function (\Illuminate\Http\Request $request) {
        foreach ($request->settings as $key => $value) {
            \App\Models\Setting::updateOrCreate(
                ['tenant_id' => $request->user()->tenant_id, 'key' => $key],
                ['value' => $value, 'group' => 'general']
            );
        }
        return response()->json(['message' => 'Settings updated.']);
    });

    // Roles list
    Route::get('/roles', fn() => response()->json(['data' => \Spatie\Permission\Models\Role::all(['id', 'name'])]));
});
