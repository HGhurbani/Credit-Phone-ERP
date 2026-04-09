<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CashboxController;
use App\Http\Controllers\Api\CashTransactionController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\CustomerCollectionController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PlatformImpersonationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PlatformSubscriptionController;
use App\Http\Controllers\Api\PlatformSubscriptionPlanController;
use App\Http\Controllers\Api\PlatformTenantController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TelegramWebhookController;
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

Route::post('/webhooks/telegram/{tenant}/{secret}', TelegramWebhookController::class);

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

    Route::middleware('permission:customer_statement.view')->get('/customers/{customer}/statement', [CustomerCollectionController::class, 'statement']);
    Route::middleware('permission:collections.followup.view')->group(function () {
        Route::get('/customers/{customer}/follow-ups', [CustomerCollectionController::class, 'followUpsIndex']);
        Route::get('/customers/{customer}/promises-to-pay', [CustomerCollectionController::class, 'promisesIndex']);
        Route::get('/customers/{customer}/reschedule-requests', [CustomerCollectionController::class, 'rescheduleIndex']);
    });
    Route::middleware('permission:collections.followup.create')->group(function () {
        Route::post('/customers/{customer}/follow-ups', [CustomerCollectionController::class, 'storeFollowUp']);
        Route::post('/customers/{customer}/promises-to-pay', [CustomerCollectionController::class, 'storePromiseToPay']);
        Route::post('/customers/{customer}/reschedule-requests', [CustomerCollectionController::class, 'storeRescheduleRequest']);
    });

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

    // Suppliers
    Route::middleware('permission:suppliers.view')->group(function () {
        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
    });
    Route::middleware('permission:suppliers.create')->post('/suppliers', [SupplierController::class, 'store']);
    Route::middleware('permission:suppliers.update')->match(['put', 'patch'], '/suppliers/{supplier}', [SupplierController::class, 'update']);
    Route::middleware('permission:suppliers.delete')->delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);

    // Purchase orders
    Route::middleware('permission:purchases.view')->group(function () {
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    });
    Route::middleware('permission:purchases.create')->post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::middleware('permission:purchases.update')->match(['put', 'patch'], '/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
    Route::middleware('permission:purchases.delete')->delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy']);
    Route::middleware('permission:purchases.update_status')->patch('/purchase-orders/{purchaseOrder}/status', [PurchaseOrderController::class, 'updateStatus']);
    Route::middleware('permission:purchases.receive')->post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);

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

    Route::middleware('permission:assistant.use')->group(function () {
        Route::get('/assistant/threads', [AssistantController::class, 'threads']);
        Route::get('/assistant/threads/{id}', [AssistantController::class, 'showThread']);
        Route::post('/assistant/messages', [AssistantController::class, 'storeMessage']);
        Route::post('/assistant/messages/{id}/confirm-delete', [AssistantController::class, 'confirmDelete']);
    });

    Route::middleware('permission:assistant.telegram.link')->group(function () {
        Route::post('/assistant/telegram/link-code', [AssistantController::class, 'generateLinkCode']);
        Route::delete('/assistant/telegram/link', [AssistantController::class, 'unlinkTelegram']);
    });

    // Roles (assignable list for user forms)
    Route::middleware('permission:roles.view')->get('/roles', [RoleController::class, 'index']);

    // Cashboxes & cash movements
    Route::middleware('permission:cashboxes.view')->group(function () {
        Route::get('/cashboxes', [CashboxController::class, 'index']);
        Route::get('/cashboxes/{cashbox}', [CashboxController::class, 'show']);
    });
    Route::middleware('permission:cashboxes.manage')->group(function () {
        Route::post('/cashboxes', [CashboxController::class, 'store']);
        Route::match(['put', 'patch'], '/cashboxes/{cashbox}', [CashboxController::class, 'update']);
        Route::delete('/cashboxes/{cashbox}', [CashboxController::class, 'destroy']);
        Route::post('/cashboxes/{cashbox}/transactions', [CashboxController::class, 'storeTransaction']);
        Route::post('/cashboxes/{cashbox}/adjustment', [CashboxController::class, 'storeAdjustment']);
    });

    Route::middleware('permission:cash_transactions.view')->group(function () {
        Route::get('/cash-transactions', [CashTransactionController::class, 'index']);
        Route::get('/cash-transactions/{cashTransaction}', [CashTransactionController::class, 'show']);
    });

    Route::middleware('permission:journal_entries.view')->group(function () {
        Route::get('/journal-entries', [JournalEntryController::class, 'index']);
        Route::get('/journal-entries/{journalEntry}', [JournalEntryController::class, 'show']);
    });

    // Expenses
    Route::middleware('permission:expenses.view')->group(function () {
        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show']);
    });
    Route::middleware('permission:expenses.create')->post('/expenses', [ExpenseController::class, 'store']);
    Route::middleware('permission:expenses.update')->group(function () {
        Route::match(['put', 'patch'], '/expenses/{expense}', [ExpenseController::class, 'update']);
        Route::post('/expenses/{expense}/cancel', [ExpenseController::class, 'cancel']);
    });
    Route::middleware('permission:expenses.delete')->delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);

    // Platform management (super admin only; enforced inside controllers)
    Route::prefix('platform')->group(function () {
        Route::get('/tenants', [PlatformTenantController::class, 'index']);
        Route::post('/tenants', [PlatformTenantController::class, 'store']);
        Route::post('/tenants/{tenant}/impersonate', [PlatformImpersonationController::class, 'store']);
        Route::match(['put', 'patch'], '/tenants/{tenant}', [PlatformTenantController::class, 'update']);
        Route::delete('/tenants/{tenant}', [PlatformTenantController::class, 'destroy']);

        Route::get('/plans', [PlatformSubscriptionPlanController::class, 'index']);
        Route::post('/plans', [PlatformSubscriptionPlanController::class, 'store']);
        Route::match(['put', 'patch'], '/plans/{plan}', [PlatformSubscriptionPlanController::class, 'update']);
        Route::delete('/plans/{plan}', [PlatformSubscriptionPlanController::class, 'destroy']);

        Route::get('/subscriptions', [PlatformSubscriptionController::class, 'index']);
        Route::post('/subscriptions', [PlatformSubscriptionController::class, 'store']);
        Route::match(['put', 'patch'], '/subscriptions/{subscription}', [PlatformSubscriptionController::class, 'update']);
        Route::delete('/subscriptions/{subscription}', [PlatformSubscriptionController::class, 'destroy']);
    });
});
