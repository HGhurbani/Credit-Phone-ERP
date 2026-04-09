<?php

namespace App\Services\Assistant;

use App\Http\Resources\BranchResource;
use App\Http\Resources\CashTransactionResource;
use App\Http\Resources\CashboxResource;
use App\Http\Resources\ContractResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\PurchaseOrderResource;
use App\Http\Resources\SupplierResource;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashTransaction;
use App\Models\Cashbox;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\ContractService;
use App\Services\ExpenseService;
use App\Services\GoodsReceiptService;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PurchaseOrderService;
use App\Support\SettingsCatalog;
use App\Support\TenantBranchScope;
use App\Support\TenantSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AssistantActionService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly GoodsReceiptService $goodsReceiptService,
        private readonly ContractService $contractService,
        private readonly PaymentService $paymentService,
        private readonly InvoiceService $invoiceService,
        private readonly CashboxService $cashboxService,
        private readonly ExpenseService $expenseService,
        private readonly AssistantPdfService $assistantPdfService,
        private readonly AssistantCatalogService $catalogAssistant,
        private readonly AssistantCollectionsService $collectionsAssistant,
        private readonly AssistantPlatformService $platformAssistant,
    ) {}

    public function execute(User $user, array $plan, string $channel, bool $confirmedDelete = false): array
    {
        $module = $plan['module'] ?? 'unsupported';
        $operation = $plan['operation'] ?? 'unsupported';
        $target = isset($plan['target']) && is_string($plan['target']) ? trim($plan['target']) : null;
        $arguments = is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [];

        return match ($module) {
            'customers' => $this->handleCustomers($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'products' => $this->handleProducts($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'categories', 'brands', 'stock' => $this->catalogAssistant->execute($user, $module, $operation, $target, $arguments, $channel, $confirmedDelete),
            'collections' => $this->collectionsAssistant->execute($user, $operation, $target, $arguments, $channel),
            'orders' => $this->handleOrders($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'users' => $this->handleUsers($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'branches' => $this->handleBranches($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'suppliers' => $this->handleSuppliers($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'purchases' => $this->handlePurchases($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'contracts' => $this->handleContracts($user, $operation, $target, $arguments, $channel),
            'payments' => $this->handlePayments($user, $operation, $target, $arguments, $channel),
            'invoices' => $this->handleInvoices($user, $operation, $target, $arguments, $channel),
            'cashboxes' => $this->handleCashboxes($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'cash_transactions' => $this->handleCashTransactions($user, $operation, $target, $arguments, $channel),
            'expenses' => $this->handleExpenses($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            'reports' => $this->handleReports($user, $operation, $target, $arguments, $channel),
            'database' => $this->handleDatabase($user, $operation, $arguments, $channel),
            'settings' => $this->handleSettings($user, $operation, $arguments, $channel),
            'platform' => $this->platformAssistant->execute($user, $operation, $target, $arguments, $channel, $confirmedDelete),
            default => $this->rejected($user, 'هذه الوحدة غير مدعومة حالياً.', 'This module is not supported right now.'),
        };
    }

    private function handleCustomers(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->queryCustomers($user, $target, $arguments),
            'create' => $this->createCustomer($user, $arguments, $channel),
            'update' => $this->updateCustomer($user, $target, $arguments, $channel),
            'delete' => $this->deleteCustomer($user, $target, $channel, $confirmedDelete),
            'print' => $this->printCustomerStatement($user, $target, $arguments),
            default => $this->rejected($user, 'عملية العملاء غير مدعومة.', 'Customer operation is not supported.'),
        };
    }

    private function handleProducts(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->queryProducts($user, $target, $arguments),
            'create' => $this->createProduct($user, $arguments, $channel),
            'update' => $this->updateProduct($user, $target, $arguments, $channel),
            'delete' => $this->deleteProduct($user, $target, $channel, $confirmedDelete),
            default => $this->rejected($user, 'عملية المنتجات غير مدعومة.', 'Product operation is not supported.'),
        };
    }

    private function handleOrders(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->queryOrders($user, $target, $arguments),
            'create' => $this->createOrder($user, $arguments, $channel),
            'update' => $this->updateOrder($user, $target, $arguments, $channel),
            'delete' => $this->deleteOrder($user, $target, $channel, $confirmedDelete),
            'print' => $this->rejected($user, 'طباعة الطلبات غير متاحة حالياً عبر الوكيل.', 'Printing orders is not available through the assistant yet.'),
            default => $this->rejected($user, 'عملية الطلبات غير مدعومة.', 'Order operation is not supported.'),
        };
    }

    private function handleUsers(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->queryUsers($user, $target, $arguments),
            'create' => $this->createUser($user, $arguments, $channel),
            'update' => $this->updateUser($user, $target, $arguments, $channel),
            'delete' => $this->deleteUser($user, $target, $channel, $confirmedDelete),
            default => $this->rejected($user, 'عملية المستخدمين غير مدعومة.', 'User operation is not supported.'),
        };
    }

    private function handleBranches(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->queryBranches($user, $target, $arguments),
            'create' => $this->createBranch($user, $arguments, $channel),
            'update' => $this->updateBranch($user, $target, $arguments, $channel),
            'delete' => $this->deleteBranch($user, $target, $channel, $confirmedDelete),
            default => $this->rejected($user, 'عملية الفروع غير مدعومة.', 'Branch operation is not supported.'),
        };
    }

    private function handleSuppliers(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->querySuppliers($user, $target, $arguments),
            'create' => $this->createSupplier($user, $arguments, $channel),
            'update' => $this->updateSupplier($user, $target, $arguments, $channel),
            'delete' => $this->deleteSupplier($user, $target, $channel, $confirmedDelete),
            'print' => $this->rejected($user, 'لا يوجد قالب طباعة مباشر للموردين حالياً.', 'There is no direct print template for suppliers yet.'),
            default => $this->rejected($user, 'عملية الموردين غير مدعومة.', 'Supplier operation is not supported.'),
        };
    }

    private function handlePurchases(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->queryPurchases($user, $target, $arguments),
            'create' => $this->createPurchase($user, $arguments, $channel),
            'update' => $this->updatePurchase($user, $target, $arguments, $channel),
            'delete' => $this->deletePurchase($user, $target, $channel, $confirmedDelete),
            'print' => $this->printPurchaseOrder($user, $target, $arguments),
            default => $this->rejected($user, 'عملية المشتريات غير مدعومة.', 'Purchase operation is not supported.'),
        };
    }

    private function handleContracts(User $user, string $operation, ?string $target, array $arguments, string $channel): array
    {
        return match ($operation) {
            'query' => $this->queryContracts($user, $target, $arguments),
            'create' => $this->createContract($user, $arguments, $channel),
            'print' => $this->printContract($user, $target, $arguments),
            default => $this->rejected($user, 'عملية العقود غير مدعومة.', 'Contract operation is not supported.'),
        };
    }

    private function handlePayments(User $user, string $operation, ?string $target, array $arguments, string $channel): array
    {
        return match ($operation) {
            'query' => $this->queryPayments($user, $target, $arguments),
            'create' => $this->createPayment($user, $arguments, $channel),
            'print' => $this->printPaymentReceipt($user, $target, $arguments),
            default => $this->rejected($user, 'عملية المدفوعات غير مدعومة.', 'Payment operation is not supported.'),
        };
    }

    private function handleInvoices(User $user, string $operation, ?string $target, array $arguments, string $channel): array
    {
        return match ($operation) {
            'query' => $this->queryInvoices($user, $target, $arguments),
            'create', 'update' => $this->updateInvoice($user, $target, $arguments, $channel),
            'print' => $this->printInvoice($user, $target, $arguments),
            default => $this->rejected($user, 'عملية الفواتير غير مدعومة.', 'Invoice operation is not supported.'),
        };
    }

    private function handleCashboxes(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->queryCashboxes($user, $target, $arguments),
            'create' => $this->createCashbox($user, $arguments, $channel),
            'update' => $this->updateCashbox($user, $target, $arguments, $channel),
            'delete' => $this->deleteCashbox($user, $target, $channel, $confirmedDelete),
            'print' => $this->rejected($user, 'لطباعة سند صندوق، حدّد حركة الصندوق نفسها.', 'To print a cash voucher, specify the cash transaction itself.'),
            default => $this->rejected($user, 'عملية الصناديق غير مدعومة.', 'Cashbox operation is not supported.'),
        };
    }

    private function handleCashTransactions(User $user, string $operation, ?string $target, array $arguments, string $channel): array
    {
        return match ($operation) {
            'query' => $this->queryCashTransactions($user, $target, $arguments),
            'print' => $this->printCashVoucher($user, $target, $arguments),
            default => $this->rejected($user, 'عملية حركة الصندوق غير مدعومة.', 'Cash transaction operation is not supported.'),
        };
    }

    private function handleExpenses(User $user, string $operation, ?string $target, array $arguments, string $channel, bool $confirmedDelete): array
    {
        return match ($operation) {
            'query' => $this->queryExpenses($user, $target, $arguments),
            'create' => $this->createExpense($user, $arguments, $channel),
            'update' => $this->updateExpense($user, $target, $arguments, $channel),
            'delete' => $this->deleteExpense($user, $target, $channel, $confirmedDelete),
            default => $this->rejected($user, 'عملية المصروفات غير مدعومة.', 'Expense operation is not supported.'),
        };
    }

    private function handleReports(User $user, string $operation, ?string $target, array $arguments, string $channel): array
    {
        if ($operation !== 'run') {
            return $this->rejected($user, 'يمكن تشغيل التقارير فقط من خلال الوكيل.', 'Only running reports is supported for reports.');
        }

        $requestText = isset($arguments['request_text']) && is_string($arguments['request_text'])
            ? $arguments['request_text']
            : (is_string($target) ? $target : '');

        $reportType = $this->normalizeReportType($arguments['report_type'] ?? $target ?? $requestText);
        if ($reportType === null) {
            return $this->clarification(
                $user,
                'لم أفهم نوع التقرير المطلوب. اختر نوع التقرير: 1) المبيعات 2) التحصيل 3) العقود النشطة 4) المتأخرات 5) أداء الفروع 6) أداء الموظفين.',
                'I could not determine the report type. Choose one: 1) sales 2) collections 3) active contracts 4) overdue 5) branch performance 6) agent performance.',
                [
                    'clarification' => [
                        'kind' => 'selection',
                        'field' => 'arguments.report_type',
                        'allow_none' => false,
                        'options' => [
                            ['number' => 1, 'value' => 'sales', 'label' => $this->loc($user, 'المبيعات', 'Sales')],
                            ['number' => 2, 'value' => 'collections', 'label' => $this->loc($user, 'التحصيل', 'Collections')],
                            ['number' => 3, 'value' => 'active_contracts', 'label' => $this->loc($user, 'العقود النشطة', 'Active contracts')],
                            ['number' => 4, 'value' => 'overdue', 'label' => $this->loc($user, 'المتأخرات', 'Overdue')],
                            ['number' => 5, 'value' => 'branch_performance', 'label' => $this->loc($user, 'أداء الفروع', 'Branch performance')],
                            ['number' => 6, 'value' => 'agent_performance', 'label' => $this->loc($user, 'أداء الموظفين', 'Agent performance')],
                        ],
                    ],
                ]
            );
        }

        [$from, $to] = $this->resolveReportPeriod($arguments, $requestText);
        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);
        $detailed = $this->reportNeedsDetails($arguments, $requestText);
        $limit = $this->limitFromArguments($arguments);

        $result = match ($reportType) {
            'sales' => $this->salesReport($user, $from, $to, $branchId, $detailed, $limit),
            'collections' => $this->collectionsReport($user, $from, $to, $branchId, $detailed, $limit),
            'active_contracts' => $this->activeContractsReport($user, $branchId),
            'overdue' => $this->overdueReport($user, $branchId),
            'branch_performance' => $this->branchPerformanceReport($user, $from, $to),
            'agent_performance' => $this->agentPerformanceReport($user, $from, $to),
        };

        $summaryText = $this->loc(
            $user,
            "تم تشغيل تقرير {$this->reportTypeLabel($reportType, 'ar')} للفترة {$from} إلى {$to}.",
            "Ran the {$this->reportTypeLabel($reportType, 'en')} report for {$from} to {$to}."
        );

        $this->recordAudit($user, 'assistant.report.run', null, [
            'channel' => $channel,
            'report_type' => $reportType,
            'period' => ['from' => $from, 'to' => $to],
            'details' => $detailed,
        ], $summaryText);

        return [
            'status' => 'completed',
            'summary' => $summaryText,
            'data' => $result,
        ];
    }

    private function handleSettings(User $user, string $operation, array $arguments, string $channel): array
    {
        return match ($operation) {
            'query' => $this->querySettings($user),
            'update' => $this->updateSettings($user, $arguments, $channel),
            default => $this->rejected($user, 'يمكن الاستعلام عن الإعدادات أو تعديلها فقط.', 'Settings can only be queried or updated.'),
        };
    }

    private function handleDatabase(User $user, string $operation, array $arguments, string $channel): array
    {
        if ($operation !== 'query') {
            return $this->rejected($user, 'تدعم قاعدة البيانات الاستعلامات فقط عبر الوكيل.', 'Only database queries are supported through the assistant.');
        }

        $sql = isset($arguments['sql']) && is_string($arguments['sql'])
            ? trim($arguments['sql'])
            : '';

        if ($sql === '') {
            return $this->clarification(
                $user,
                'أرسل صيغة الاستعلام المخصص أو اطلبه بوضوح حتى أتمكن من توليد استعلام قراءة فقط.',
                'Please provide the custom query clearly so I can generate a read-only SQL statement.'
            );
        }

        $guard = $this->guardReadOnlySql($user, $sql);
        if ($guard !== null) {
            return $this->rejected(
                $user,
                'تم رفض الاستعلام لأن الوصول المخصص مسموح للقراءة فقط. '.$guard,
                'The custom query was rejected because only read-only access is allowed. '.$guard
            );
        }

        $limit = $this->limitFromArguments($arguments);
        $sql = $this->appendLimitToSql($sql, $limit);
        $rows = collect(DB::select($sql))->map(fn ($row) => (array) $row)->values();
        $summary = $this->loc(
            $user,
            'تم تنفيذ الاستعلام المخصص بنجاح وإرجاع '.$rows->count().' صف.',
            'The custom query ran successfully and returned '.$rows->count().' rows.'
        );

        $this->recordAudit($user, 'assistant.database.query', null, [
            'channel' => $channel,
            'sql' => $sql,
            'row_count' => $rows->count(),
        ], $summary);

        return [
            'status' => 'completed',
            'summary' => $summary,
            'data' => [
                'items' => $rows->all(),
                'count' => $rows->count(),
                'sql' => $sql,
            ],
        ];
    }

    private function queryCustomers(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $limit = $this->limitFromArguments($arguments);
        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);

        $query = Customer::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['branch', 'createdBy'])
            ->when($search, fn ($q) => $q->search($search))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when(isset($arguments['active_only']) && $arguments['active_only'], fn ($q) => $q->where('is_active', true))
            ->latest();

        $customers = $query->limit($limit)->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$customers->count().' عميل.', 'Found '.$customers->count().' customers.'),
            'data' => [
                'items' => CustomerResource::collection($customers)->resolve(),
                'count' => $customers->count(),
            ],
        ];
    }

    private function createCustomer(User $user, array $arguments, string $channel): array
    {
        $branchId = $this->resolveBranchIdForCreate($user, $arguments);
        $data = [
            'name' => $arguments['name'] ?? null,
            'phone' => $arguments['phone'] ?? null,
            'email' => $arguments['email'] ?? null,
            'national_id' => $arguments['national_id'] ?? null,
            'id_type' => $arguments['id_type'] ?? 'national',
            'address' => $arguments['address'] ?? null,
            'city' => $arguments['city'] ?? null,
            'employer_name' => $arguments['employer_name'] ?? null,
            'monthly_salary' => $arguments['monthly_salary'] ?? null,
            'credit_score' => $arguments['credit_score'] ?? 'good',
            'notes' => $arguments['notes'] ?? null,
            'is_active' => $arguments['is_active'] ?? true,
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'id_type' => ['nullable', Rule::in(['national', 'residency', 'passport'])],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'employer_name' => ['nullable', 'string', 'max:255'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0'],
            'credit_score' => ['nullable', Rule::in(['excellent', 'good', 'fair', 'poor'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات العميل غير مكتملة أو غير صحيحة: '.$validator->errors()->first(), 'Customer data is incomplete or invalid: '.$validator->errors()->first());
        }

        $customer = Customer::create([
            ...$validator->validated(),
            'tenant_id' => $user->tenant_id,
            'branch_id' => $branchId,
            'created_by' => $user->id,
        ]);

        $customer->load(['branch', 'createdBy']);
        $summary = $this->loc($user, "تم إنشاء العميل {$customer->name} بنجاح.", "Customer {$customer->name} was created successfully.");
        $this->recordAudit($user, 'assistant.customer.create', $customer, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => CustomerResource::make($customer)->resolve()];
    }

    private function updateCustomer(User $user, ?string $target, array $arguments, string $channel): array
    {
        $customer = $this->resolveCustomerTarget($user, $target ?? ($arguments['id'] ?? null));
        if ($customer['status'] !== 'resolved') {
            return $customer['response'];
        }

        $model = $customer['model'];
        $data = array_intersect_key($arguments, array_flip([
            'name', 'phone', 'email', 'national_id', 'id_type', 'address', 'city',
            'employer_name', 'monthly_salary', 'credit_score', 'notes', 'is_active',
        ]));

        if ($data === []) {
            return $this->clarification($user, 'حدد الحقول التي تريد تعديلها للعميل.', 'Specify the customer fields you want to update.');
        }

        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'id_type' => ['nullable', Rule::in(['national', 'residency', 'passport'])],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'employer_name' => ['nullable', 'string', 'max:255'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0'],
            'credit_score' => ['nullable', Rule::in(['excellent', 'good', 'fair', 'poor'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل العميل: '.$validator->errors()->first(), 'Could not update the customer: '.$validator->errors()->first());
        }

        $oldValues = $model->only(array_keys($validator->validated()));
        $model->update($validator->validated());
        $model->load(['branch', 'createdBy']);

        $summary = $this->loc($user, "تم تعديل العميل {$model->name}.", "Customer {$model->name} was updated.");
        $this->recordAudit($user, 'assistant.customer.update', $model, [
            'channel' => $channel,
            'old' => $oldValues,
            'new' => $validator->validated(),
        ], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => CustomerResource::make($model)->resolve()];
    }

    private function deleteCustomer(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }

        $customer = $this->resolveCustomerTarget($user, $target);
        if ($customer['status'] !== 'resolved') {
            return $customer['response'];
        }

        $model = $customer['model'];
        $summary = $this->loc($user, "تم حذف العميل {$model->name}.", "Customer {$model->name} was deleted.");
        $this->recordAudit($user, 'assistant.customer.delete', $model, ['channel' => $channel], $summary);
        $model->delete();

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $model->id]];
    }

    private function queryProducts(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $limit = $this->limitFromArguments($arguments);

        $products = Product::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['category', 'brand'])
            ->when($search, fn ($q) => $q->search($search))
            ->when(isset($arguments['active_only']) && $arguments['active_only'], fn ($q) => $q->where('is_active', true))
            ->latest()
            ->limit($limit)
            ->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$products->count().' منتج.', 'Found '.$products->count().' products.'),
            'data' => [
                'items' => ProductResource::collection($products)->resolve(),
                'count' => $products->count(),
            ],
        ];
    }

    private function createProduct(User $user, array $arguments, string $channel): array
    {
        $data = [
            'name' => $arguments['name'] ?? null,
            'name_ar' => $arguments['name_ar'] ?? null,
            'category_id' => $arguments['category_id'] ?? null,
            'brand_id' => $arguments['brand_id'] ?? null,
            'sku' => $arguments['sku'] ?? null,
            'description' => $arguments['description'] ?? null,
            'cash_price' => $arguments['cash_price'] ?? null,
            'installment_price' => $arguments['installment_price'] ?? null,
            'cost_price' => $arguments['cost_price'] ?? null,
            'min_down_payment' => $arguments['min_down_payment'] ?? null,
            'allowed_durations' => $arguments['allowed_durations'] ?? null,
            'monthly_percent_of_cash' => $arguments['monthly_percent_of_cash'] ?? null,
            'fixed_monthly_amount' => $arguments['fixed_monthly_amount'] ?? null,
            'track_serial' => $arguments['track_serial'] ?? false,
            'is_active' => $arguments['is_active'] ?? true,
        ];

        $mode = TenantSettings::string($user->tenant_id, 'installment_pricing_mode', 'percentage');
        $clarification = $this->productCreateClarification($user, $data, $mode);
        if ($clarification !== null) {
            return $clarification;
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'sku' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'cash_price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'track_serial' => ['boolean'],
            'is_active' => ['boolean'],
            'min_down_payment' => ['nullable', 'numeric', 'min:0'],
            'allowed_durations' => ['nullable', 'array'],
            'allowed_durations.*' => ['integer', 'min:1'],
            'monthly_percent_of_cash' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_monthly_amount' => ['nullable', 'numeric', 'min:0'],
            'installment_price' => ['nullable', 'numeric', 'min:0'],
        ];

        if ($mode === 'fixed') {
            $rules['fixed_monthly_amount'] = ['required', 'numeric', 'min:0.01'];
            $rules['min_down_payment'] = ['required', 'numeric', 'min:0'];
            $rules['allowed_durations'] = ['required', 'array', 'min:1'];
        } else {
            $rules['installment_price'] = ['required', 'numeric', 'min:0'];
        }

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات المنتج غير مكتملة أو غير صحيحة: '.$validator->errors()->first(), 'Product data is incomplete or invalid: '.$validator->errors()->first());
        }

        $validated = $validator->validated();
        if ($mode === 'fixed') {
            $months = min(array_map('intval', $validated['allowed_durations']));
            $validated['installment_price'] = round((float) $validated['min_down_payment'] + (float) $validated['fixed_monthly_amount'] * $months, 2);
        }

        $product = Product::create([
            ...$validated,
            'tenant_id' => $user->tenant_id,
        ]);

        $product->load(['category', 'brand']);
        $summary = $this->loc($user, "تم إنشاء المنتج {$product->name}.", "Product {$product->name} was created.");
        $this->recordAudit($user, 'assistant.product.create', $product, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => ProductResource::make($product)->resolve()];
    }

    private function productCreateClarification(User $user, array $data, string $mode): ?array
    {
        if (($data['name'] ?? null) === null || trim((string) $data['name']) === '') {
            return $this->clarification($user, 'ما هو اسم المنتج الذي تريد إضافته؟', 'What is the product name you want to add?');
        }

        if ($data['cash_price'] === null || $data['cash_price'] === '') {
            return $this->clarification($user, 'ما هو سعر الكاش للمنتج؟', 'What is the product cash price?');
        }

        if ($mode === 'fixed') {
            if ($data['fixed_monthly_amount'] === null || $data['fixed_monthly_amount'] === '') {
                return $this->clarification($user, 'ما هو مبلغ القسط الشهري الثابت لهذا المنتج؟', 'What is the fixed monthly amount for this product?');
            }

            if ($data['min_down_payment'] === null || $data['min_down_payment'] === '') {
                return $this->clarification($user, 'ما هو الحد الأدنى للدفعة الأولى لهذا المنتج؟', 'What is the minimum down payment for this product?');
            }

            if (! is_array($data['allowed_durations']) || $data['allowed_durations'] === []) {
                return $this->clarification($user, 'ما هي مدد التقسيط المتاحة لهذا المنتج؟ مثال: 12، 18، 24 شهر.', 'What installment durations are available for this product? Example: 12, 18, 24 months.');
            }

            return null;
        }

        if ($data['installment_price'] === null || $data['installment_price'] === '') {
            return $this->clarification($user, 'ما هو سعر التقسيط للمنتج؟', 'What is the product installment price?');
        }

        return null;
    }

    private function updateProduct(User $user, ?string $target, array $arguments, string $channel): array
    {
        $product = $this->resolveProductTarget($user, $target ?? ($arguments['id'] ?? null));
        if ($product['status'] !== 'resolved') {
            return $product['response'];
        }

        $model = $product['model'];
        $data = array_intersect_key($arguments, array_flip([
            'name', 'name_ar', 'category_id', 'brand_id', 'sku', 'description',
            'cash_price', 'installment_price', 'cost_price', 'min_down_payment',
            'allowed_durations', 'monthly_percent_of_cash', 'fixed_monthly_amount',
            'track_serial', 'is_active',
        ]));

        if ($data === []) {
            return $this->clarification($user, 'حدد الحقول التي تريد تعديلها للمنتج.', 'Specify the product fields you want to update.');
        }

        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'sku' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'cash_price' => ['sometimes', 'numeric', 'min:0'],
            'installment_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'min_down_payment' => ['nullable', 'numeric', 'min:0'],
            'allowed_durations' => ['nullable', 'array'],
            'allowed_durations.*' => ['integer', 'min:1'],
            'monthly_percent_of_cash' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_monthly_amount' => ['nullable', 'numeric', 'min:0'],
            'track_serial' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل المنتج: '.$validator->errors()->first(), 'Could not update the product: '.$validator->errors()->first());
        }

        $validated = $validator->validated();
        $mode = TenantSettings::string($user->tenant_id, 'installment_pricing_mode', 'percentage');
        if ($mode === 'fixed') {
            $durations = $validated['allowed_durations'] ?? $model->allowed_durations ?? [];
            if ($durations) {
                $months = min(array_map('intval', $durations));
                $minDown = (float) ($validated['min_down_payment'] ?? $model->min_down_payment);
                $fixedMonthly = (float) ($validated['fixed_monthly_amount'] ?? $model->fixed_monthly_amount);
                if ($fixedMonthly > 0) {
                    $validated['installment_price'] = round($minDown + $fixedMonthly * $months, 2);
                }
            }
        }

        $oldValues = $model->only(array_keys($validated));
        $model->update($validated);
        $model->load(['category', 'brand']);

        $summary = $this->loc($user, "تم تعديل المنتج {$model->name}.", "Product {$model->name} was updated.");
        $this->recordAudit($user, 'assistant.product.update', $model, [
            'channel' => $channel,
            'old' => $oldValues,
            'new' => $validated,
        ], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => ProductResource::make($model)->resolve()];
    }

    private function deleteProduct(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }

        $product = $this->resolveProductTarget($user, $target);
        if ($product['status'] !== 'resolved') {
            return $product['response'];
        }

        $model = $product['model'];
        $summary = $this->loc($user, "تم حذف المنتج {$model->name}.", "Product {$model->name} was deleted.");
        $this->recordAudit($user, 'assistant.product.delete', $model, ['channel' => $channel], $summary);
        $model->delete();

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $model->id]];
    }

    private function queryOrders(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);
        $limit = $this->limitFromArguments($arguments);

        $orders = Order::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['customer', 'branch', 'salesAgent'])
            ->when($search, function ($q) use ($search) {
                $q->where('order_number', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', fn ($cq) => $cq->search($search));
            })
            ->when(isset($arguments['status']), fn ($q) => $q->where('status', $arguments['status']))
            ->when(isset($arguments['type']), fn ($q) => $q->where('type', $arguments['type']))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->latest()
            ->limit($limit)
            ->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$orders->count().' طلب.', 'Found '.$orders->count().' orders.'),
            'data' => [
                'items' => OrderResource::collection($orders)->resolve(),
                'count' => $orders->count(),
            ],
        ];
    }

    private function createOrder(User $user, array $arguments, string $channel): array
    {
        $branchId = $this->resolveBranchIdForCreate($user, $arguments);
        $customerId = $this->resolveCustomerIdFromArguments($user, $arguments);
        if (is_array($customerId)) {
            return $customerId;
        }

        $items = $this->normalizeOrderItems($arguments['items'] ?? []);
        if ($items === []) {
            return $this->clarification($user, 'لم أتمكن من تحديد بنود الطلب. أرسل العناصر مع الكمية لكل منتج.', 'I could not determine the order items. Please include each item with its quantity.');
        }

        $data = [
            'customer_id' => $customerId,
            'branch_id' => $branchId,
            'type' => $arguments['type'] ?? null,
            'items' => $items,
            'discount_amount' => $arguments['discount_amount'] ?? null,
            'notes' => $arguments['notes'] ?? null,
        ];

        $validator = Validator::make($data, [
            'customer_id' => ['required', 'exists:customers,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'type' => ['required', Rule::in(['cash', 'installment'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.serial_number' => ['nullable', 'string', 'max:100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات الطلب غير مكتملة أو غير صحيحة: '.$validator->errors()->first(), 'Order data is incomplete or invalid: '.$validator->errors()->first());
        }

        $order = $this->orderService->create(
            $validator->validated(),
            (int) $user->tenant_id,
            (int) ($branchId ?? $user->branch_id),
            (int) $user->id,
        );

        $order->load(['customer', 'branch', 'salesAgent', 'items.product']);
        $summary = $this->loc($user, "تم إنشاء الطلب {$order->order_number}.", "Order {$order->order_number} was created.");
        $this->recordAudit($user, 'assistant.order.create', $order, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => OrderResource::make($order)->resolve()];
    }

    private function updateOrder(User $user, ?string $target, array $arguments, string $channel): array
    {
        $order = $this->resolveOrderTarget($user, $target ?? ($arguments['id'] ?? null));
        if ($order['status'] !== 'resolved') {
            return $order['response'];
        }

        /** @var Order $model */
        $model = $order['model'];
        if (! in_array($model->status, ['draft', 'cancelled'], true)) {
            return $this->rejected($user, 'يمكن تعديل الطلبات في حالة مسودة أو ملغي فقط.', 'Only draft or cancelled orders can be updated.');
        }

        $updateData = [];
        if (array_key_exists('customer_id', $arguments) || array_key_exists('customer', $arguments)) {
            $customerId = $this->resolveCustomerIdFromArguments($user, $arguments);
            if (is_array($customerId)) {
                return $customerId;
            }
            $updateData['customer_id'] = $customerId;
        }

        if (array_key_exists('branch_id', $arguments) || array_key_exists('branch', $arguments)) {
            $updateData['branch_id'] = $this->resolveBranchIdForCreate($user, ['branch_id' => $arguments['branch_id'] ?? $arguments['branch']]);
        }

        foreach (['type', 'discount_amount', 'notes'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $updateData[$key] = $arguments[$key];
            }
        }

        $newItems = null;
        if (array_key_exists('items', $arguments)) {
            $newItems = $this->normalizeOrderItems($arguments['items']);
            if ($newItems === []) {
                return $this->clarification($user, 'تعذر فهم عناصر الطلب الجديدة.', 'Could not understand the updated order items.');
            }
        }

        if ($updateData === [] && $newItems === null) {
            return $this->clarification($user, 'حدد الحقول أو العناصر التي تريد تعديلها في الطلب.', 'Specify the order fields or items you want to update.');
        }

        $validator = Validator::make([
            ...$updateData,
            'items' => $newItems,
        ], [
            'customer_id' => ['sometimes', 'exists:customers,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'type' => ['sometimes', Rule::in(['cash', 'installment'])],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.product_id' => ['required_with:items', 'exists:products,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.serial_number' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل الطلب: '.$validator->errors()->first(), 'Could not update the order: '.$validator->errors()->first());
        }

        $validated = $validator->validated();
        $oldValues = $model->only(['customer_id', 'branch_id', 'type', 'discount_amount', 'notes', 'subtotal', 'total']);

        DB::transaction(function () use ($model, $validated) {
            $items = $validated['items'] ?? null;
            unset($validated['items']);

            if ($validated !== []) {
                $model->update($validated);
            }

            if ($items !== null) {
                $model->items()->delete();
                $rebuilt = $this->buildOrderItemsPayload($user, $items, $model->type);
                $model->items()->createMany($rebuilt['items']);
                $model->update([
                    'subtotal' => $rebuilt['subtotal'],
                    'discount_amount' => $validated['discount_amount'] ?? $model->discount_amount,
                    'total' => $rebuilt['subtotal'] - (float) ($validated['discount_amount'] ?? $model->discount_amount ?? 0),
                ]);
            } else {
                $subtotal = (float) $model->items()->sum('total');
                $discountAmount = (float) ($validated['discount_amount'] ?? $model->discount_amount ?? 0);
                $model->update([
                    'subtotal' => $subtotal,
                    'total' => $subtotal - $discountAmount,
                ]);
            }
        });

        $model->refresh()->load(['customer', 'branch', 'salesAgent', 'items.product']);
        $summary = $this->loc($user, "تم تعديل الطلب {$model->order_number}.", "Order {$model->order_number} was updated.");
        $this->recordAudit($user, 'assistant.order.update', $model, [
            'channel' => $channel,
            'old' => $oldValues,
            'new' => $validated,
        ], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => OrderResource::make($model)->resolve()];
    }

    private function deleteOrder(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }

        $order = $this->resolveOrderTarget($user, $target);
        if ($order['status'] !== 'resolved') {
            return $order['response'];
        }

        /** @var Order $model */
        $model = $order['model'];
        if (! in_array($model->status, ['draft', 'cancelled'], true)) {
            return $this->rejected($user, 'يمكن حذف الطلبات في حالة مسودة أو ملغي فقط.', 'Only draft or cancelled orders can be deleted.');
        }

        $summary = $this->loc($user, "تم حذف الطلب {$model->order_number}.", "Order {$model->order_number} was deleted.");
        $this->recordAudit($user, 'assistant.order.delete', $model, ['channel' => $channel], $summary);
        $model->delete();

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $model->id]];
    }

    private function queryUsers(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $limit = $this->limitFromArguments($arguments);

        $users = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['branch', 'roles'])
            ->when($search, fn ($q) => $q->where(fn ($sq) => $sq
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.$search.'%')))
            ->when(isset($arguments['branch_id']), fn ($q) => $q->where('branch_id', $arguments['branch_id']))
            ->when(isset($arguments['active_only']) && $arguments['active_only'], fn ($q) => $q->where('is_active', true))
            ->latest()
            ->limit($limit)
            ->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$users->count().' مستخدم.', 'Found '.$users->count().' users.'),
            'data' => [
                'items' => UserResource::collection($users)->resolve(),
                'count' => $users->count(),
            ],
        ];
    }

    private function createUser(User $user, array $arguments, string $channel): array
    {
        $data = [
            'name' => $arguments['name'] ?? null,
            'email' => $arguments['email'] ?? null,
            'phone' => $arguments['phone'] ?? null,
            'password' => $arguments['password'] ?? null,
            'branch_id' => $arguments['branch_id'] ?? null,
            'role' => $arguments['role'] ?? null,
            'locale' => $arguments['locale'] ?? null,
            'is_active' => $arguments['is_active'] ?? true,
        ];

        if ($data['branch_id'] !== null) {
            $data['branch_id'] = $this->resolveBranchIdForCreate($user, ['branch_id' => $data['branch_id']]);
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات المستخدم غير مكتملة أو غير صحيحة: '.$validator->errors()->first(), 'User data is incomplete or invalid: '.$validator->errors()->first());
        }

        $validated = $validator->validated();
        $newUser = User::create([
            ...$validated,
            'tenant_id' => $user->tenant_id,
            'password' => Hash::make($validated['password']),
        ]);

        if (! empty($validated['role'])) {
            $newUser->assignRole($validated['role']);
        }

        $newUser->load(['branch', 'roles']);
        $summary = $this->loc($user, "تم إنشاء المستخدم {$newUser->name}.", "User {$newUser->name} was created.");
        $this->recordAudit($user, 'assistant.user.create', $newUser, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => UserResource::make($newUser)->resolve()];
    }

    private function updateUser(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolveUserTarget($user, $target ?? ($arguments['id'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var User $model */
        $model = $resolved['model'];
        $data = array_intersect_key($arguments, array_flip([
            'name', 'email', 'phone', 'password', 'branch_id', 'role', 'locale', 'is_active',
        ]));

        if ($data === []) {
            return $this->clarification($user, 'حدد الحقول التي تريد تعديلها للمستخدم.', 'Specify the user fields you want to update.');
        }

        if (array_key_exists('branch_id', $data) && $data['branch_id'] !== null) {
            $data['branch_id'] = $this->resolveBranchIdForCreate($user, ['branch_id' => $data['branch_id']]);
        }

        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($model->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:8'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل المستخدم: '.$validator->errors()->first(), 'Could not update the user: '.$validator->errors()->first());
        }

        $validated = $validator->validated();
        $oldValues = $model->only(array_keys(array_diff_key($validated, ['password' => true])));

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $role = $validated['role'] ?? null;
        unset($validated['role']);
        $model->update($validated);
        if ($role !== null) {
            $model->syncRoles([$role]);
        }

        $model->load(['branch', 'roles']);
        $summary = $this->loc($user, "تم تعديل المستخدم {$model->name}.", "User {$model->name} was updated.");
        $this->recordAudit($user, 'assistant.user.update', $model, [
            'channel' => $channel,
            'old' => $oldValues,
            'new' => array_diff_key($data, ['password' => true]),
        ], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => UserResource::make($model)->resolve()];
    }

    private function deleteUser(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }

        $resolved = $this->resolveUserTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var User $model */
        $model = $resolved['model'];
        if ((int) $model->id === (int) $user->id) {
            return $this->rejected($user, 'لا يمكنك حذف حسابك الحالي.', 'You cannot delete your own account.');
        }

        $summary = $this->loc($user, "تم حذف المستخدم {$model->name}.", "User {$model->name} was deleted.");
        $this->recordAudit($user, 'assistant.user.delete', $model, ['channel' => $channel], $summary);
        $model->delete();

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $model->id]];
    }

    private function queryBranches(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $branches = Branch::query()
            ->where('tenant_id', $user->tenant_id)
            ->when(TenantBranchScope::isBranchScoped($user), fn ($q) => $q->where('id', $user->branch_id))
            ->when($search, fn ($q) => $q->where(fn ($sq) => $sq
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('code', 'like', '%'.$search.'%')
                ->orWhere('city', 'like', '%'.$search.'%')))
            ->when(isset($arguments['active_only']) && $arguments['active_only'], fn ($q) => $q->where('is_active', true))
            ->withCount('users')
            ->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$branches->count().' فرع.', 'Found '.$branches->count().' branches.'),
            'data' => [
                'items' => BranchResource::collection($branches)->resolve(),
                'count' => $branches->count(),
            ],
        ];
    }

    private function createBranch(User $user, array $arguments, string $channel): array
    {
        $validator = Validator::make($arguments, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'is_main' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات الفرع غير صحيحة: '.$validator->errors()->first(), 'Branch data is invalid: '.$validator->errors()->first());
        }

        $branch = Branch::create([
            ...$validator->validated(),
            'tenant_id' => $user->tenant_id,
        ]);

        $summary = $this->loc($user, "تم إنشاء الفرع {$branch->name}.", "Branch {$branch->name} was created.");
        $this->recordAudit($user, 'assistant.branch.create', $branch, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => BranchResource::make($branch)->resolve()];
    }

    private function updateBranch(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolveBranchTarget($user, $target ?? ($arguments['id'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Branch $model */
        $model = $resolved['model'];
        $data = array_intersect_key($arguments, array_flip([
            'name', 'code', 'phone', 'email', 'address', 'city', 'is_active',
        ]));

        if ($data === []) {
            return $this->clarification($user, 'حدد الحقول التي تريد تعديلها للفرع.', 'Specify the branch fields you want to update.');
        }

        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل الفرع: '.$validator->errors()->first(), 'Could not update the branch: '.$validator->errors()->first());
        }

        $oldValues = $model->only(array_keys($validator->validated()));
        $model->update($validator->validated());
        $summary = $this->loc($user, "تم تعديل الفرع {$model->name}.", "Branch {$model->name} was updated.");
        $this->recordAudit($user, 'assistant.branch.update', $model, [
            'channel' => $channel,
            'old' => $oldValues,
            'new' => $validator->validated(),
        ], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => BranchResource::make($model)->resolve()];
    }

    private function deleteBranch(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }

        $resolved = $this->resolveBranchTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Branch $model */
        $model = $resolved['model'];
        if ($model->is_main) {
            return $this->rejected($user, 'لا يمكن حذف الفرع الرئيسي.', 'The main branch cannot be deleted.');
        }

        $summary = $this->loc($user, "تم حذف الفرع {$model->name}.", "Branch {$model->name} was deleted.");
        $this->recordAudit($user, 'assistant.branch.delete', $model, ['channel' => $channel], $summary);
        $model->delete();

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $model->id]];
    }

    private function querySettings(User $user): array
    {
        $settings = [];
        foreach (Setting::query()->where('tenant_id', $user->tenant_id)->get() as $setting) {
            if (SettingsCatalog::isSecret($setting->key)) {
                $settings[$setting->key.'_configured'] = ! empty($setting->getRawOriginal('value'));
                continue;
            }

            $settings[$setting->key] = $setting->value;
        }

        $settings['assistant_openai_api_key_configured'] = TenantSettings::has($user->tenant_id, 'assistant_openai_api_key');
        $settings['assistant_gemini_api_key_configured'] = TenantSettings::has($user->tenant_id, 'assistant_gemini_api_key');
        $settings['telegram_bot_token_configured'] = TenantSettings::has($user->tenant_id, 'telegram_bot_token');
        $settings['telegram_webhook_secret_configured'] = TenantSettings::has($user->tenant_id, 'telegram_webhook_secret');

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم جلب الإعدادات المتاحة بشكل آمن.', 'Fetched the available settings safely.'),
            'data' => $settings,
        ];
    }

    private function updateSettings(User $user, array $arguments, string $channel): array
    {
        $settings = $arguments['settings'] ?? $arguments;
        if (! is_array($settings) || $settings === []) {
            return $this->clarification($user, 'أرسل الإعدادات المراد تعديلها بصيغة مفتاح وقيمة.', 'Please provide the settings to update as key/value pairs.');
        }

        $updated = [];
        foreach ($settings as $key => $value) {
            if (! is_string($key) || $key === '' || strlen($key) > 100) {
                return $this->clarification($user, 'اسم مفتاح الإعداد غير صالح.', 'A setting key is invalid.');
            }

            if ($key === 'telegram_webhook_url' || str_ends_with($key, '_configured')) {
                continue;
            }

            $type = SettingsCatalog::typeForKey($key);
            if (SettingsCatalog::isSecret($key)) {
                if ($value === null || $value === '') {
                    continue;
                }
                $value = Crypt::encryptString((string) $value);
            }

            Setting::updateOrCreate(
                ['tenant_id' => $user->tenant_id, 'key' => $key],
                ['value' => $value, 'group' => SettingsCatalog::groupForKey($key), 'type' => $type]
            );

            $updated[] = $key;
        }

        if (($settings['telegram_enabled'] ?? false) && ! TenantSettings::has($user->tenant_id, 'telegram_webhook_secret')) {
            $secret = substr(Hash::make((string) now()->timestamp.microtime(true)), 0, 40);
            Setting::updateOrCreate(
                ['tenant_id' => $user->tenant_id, 'key' => 'telegram_webhook_secret'],
                ['value' => Crypt::encryptString($secret), 'group' => 'telegram', 'type' => 'encrypted']
            );
            $updated[] = 'telegram_webhook_secret';
        }

        $summary = $this->loc($user, 'تم تحديث الإعدادات المطلوبة.', 'The requested settings were updated.');
        $this->recordAudit($user, 'assistant.settings.update', null, ['channel' => $channel, 'keys' => $updated], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['updated_keys' => $updated]];
    }

    private function querySuppliers(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $limit = $this->limitFromArguments($arguments);

        $suppliers = Supplier::query()
            ->where('tenant_id', $user->tenant_id)
            ->when($search, fn ($q) => $q->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%')))
            ->when(isset($arguments['active_only']) && $arguments['active_only'], fn ($q) => $q->where('is_active', true))
            ->latest()
            ->limit($limit)
            ->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$suppliers->count().' مورد.', 'Found '.$suppliers->count().' suppliers.'),
            'data' => [
                'items' => SupplierResource::collection($suppliers)->resolve(),
                'count' => $suppliers->count(),
            ],
        ];
    }

    private function createSupplier(User $user, array $arguments, string $channel): array
    {
        $data = [
            'name' => $arguments['name'] ?? null,
            'phone' => $arguments['phone'] ?? null,
            'email' => $arguments['email'] ?? null,
            'contact_person' => $arguments['contact_person'] ?? null,
            'tax_number' => $arguments['tax_number'] ?? null,
            'address' => $arguments['address'] ?? null,
            'notes' => $arguments['notes'] ?? null,
            'is_active' => $arguments['is_active'] ?? true,
        ];

        if (blank($data['name'])) {
            return $this->clarification($user, 'ما هو اسم المورد؟', 'What is the supplier name?');
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات المورد غير مكتملة أو غير صحيحة: '.$validator->errors()->first(), 'Supplier data is incomplete or invalid: '.$validator->errors()->first());
        }

        $supplier = Supplier::create([
            ...$validator->validated(),
            'tenant_id' => $user->tenant_id,
        ]);

        $summary = $this->loc($user, "تم إنشاء المورد {$supplier->name}.", "Supplier {$supplier->name} was created.");
        $this->recordAudit($user, 'assistant.supplier.create', $supplier, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => SupplierResource::make($supplier)->resolve()];
    }

    private function updateSupplier(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolveSupplierTarget($user, $target ?? ($arguments['id'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        $model = $resolved['model'];
        $data = array_intersect_key($arguments, array_flip(['name', 'phone', 'email', 'contact_person', 'tax_number', 'address', 'notes', 'is_active']));
        if ($data === []) {
            return $this->clarification($user, 'ما هي بيانات المورد التي تريد تعديلها؟', 'Which supplier fields do you want to update?');
        }

        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل المورد: '.$validator->errors()->first(), 'Could not update the supplier: '.$validator->errors()->first());
        }

        $oldValues = $model->only(array_keys($validator->validated()));
        $model->update($validator->validated());
        $summary = $this->loc($user, "تم تعديل المورد {$model->name}.", "Supplier {$model->name} was updated.");
        $this->recordAudit($user, 'assistant.supplier.update', $model, ['channel' => $channel, 'old' => $oldValues, 'new' => $validator->validated()], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => SupplierResource::make($model->fresh())->resolve()];
    }

    private function deleteSupplier(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }

        $resolved = $this->resolveSupplierTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        $model = $resolved['model'];
        if ($model->purchaseOrders()->exists()) {
            return $this->rejected($user, 'لا يمكن حذف المورد لأنه مرتبط بأوامر شراء.', 'Cannot delete this supplier because it is linked to purchase orders.');
        }

        $summary = $this->loc($user, "تم حذف المورد {$model->name}.", "Supplier {$model->name} was deleted.");
        $this->recordAudit($user, 'assistant.supplier.delete', $model, ['channel' => $channel], $summary);
        $model->delete();

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $model->id]];
    }

    private function queryPurchases(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $limit = $this->limitFromArguments($arguments);
        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);

        $items = PurchaseOrder::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['supplier', 'branch', 'createdBy'])
            ->when($search, fn ($q) => $q->where('purchase_number', 'like', '%'.$search.'%'))
            ->when(isset($arguments['status']), fn ($q) => $q->where('status', $arguments['status']))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->latest()
            ->limit($limit)
            ->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$items->count().' أمر شراء.', 'Found '.$items->count().' purchase orders.'),
            'data' => [
                'items' => PurchaseOrderResource::collection($items)->resolve(),
                'count' => $items->count(),
            ],
        ];
    }

    private function createPurchase(User $user, array $arguments, string $channel): array
    {
        $supplierId = $this->resolveSupplierIdFromArguments($user, $arguments);
        if (is_array($supplierId)) {
            return $supplierId;
        }

        $branchId = $this->resolveBranchIdForCreate($user, $arguments);
        if ($branchId === null) {
            return $this->clarification($user, 'حدد الفرع الخاص بأمر الشراء.', 'Specify the branch for this purchase order.');
        }

        $data = [
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'order_date' => $arguments['order_date'] ?? now()->toDateString(),
            'expected_date' => $arguments['expected_date'] ?? null,
            'discount_amount' => $arguments['discount_amount'] ?? 0,
            'notes' => $arguments['notes'] ?? null,
            'status' => $arguments['status'] ?? 'draft',
            'items' => is_array($arguments['items'] ?? null) ? $arguments['items'] : [],
        ];

        $validator = Validator::make($data, [
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where(fn ($q) => $q->where('tenant_id', $user->tenant_id))],
            'branch_id' => ['required', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', $user->tenant_id))],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['draft', 'ordered'])],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', Rule::exists('products', 'id')->where(fn ($q) => $q->where('tenant_id', $user->tenant_id))],
            'items.*.quantity' => ['required_with:items.*.product_id', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required_with:items.*.product_id', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات أمر الشراء غير مكتملة أو غير صحيحة: '.$validator->errors()->first(), 'Purchase order data is incomplete or invalid: '.$validator->errors()->first());
        }

        $purchase = $this->purchaseOrderService->create($validator->validated(), (int) $user->tenant_id, (int) $user->id);
        $summary = $this->loc($user, "تم إنشاء أمر الشراء {$purchase->purchase_number}.", "Purchase order {$purchase->purchase_number} was created.");
        $this->recordAudit($user, 'assistant.purchase.create', $purchase, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => PurchaseOrderResource::make($purchase)->resolve()];
    }

    private function updatePurchase(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolvePurchaseOrderTarget($user, $target ?? ($arguments['id'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var PurchaseOrder $model */
        $model = $resolved['model'];

        if (isset($arguments['status']) && in_array($arguments['status'], ['ordered', 'cancelled'], true)) {
            $updated = $this->purchaseOrderService->updateStatus($model, (string) $arguments['status'], (int) $user->tenant_id);
            $summary = $this->loc($user, "تم تحديث حالة أمر الشراء {$updated->purchase_number}.", "Purchase order {$updated->purchase_number} status was updated.");
            $this->recordAudit($user, 'assistant.purchase.status', $updated, ['channel' => $channel, 'status' => $arguments['status']], $summary);

            return ['status' => 'completed', 'summary' => $summary, 'data' => PurchaseOrderResource::make($updated)->resolve()];
        }

        $data = array_intersect_key($arguments, array_flip(['order_date', 'expected_date', 'discount_amount', 'notes', 'items']));
        if (isset($arguments['supplier_id']) || isset($arguments['supplier']) || isset($arguments['supplier_name'])) {
            $supplierId = $this->resolveSupplierIdFromArguments($user, $arguments);
            if (is_array($supplierId)) {
                return $supplierId;
            }
            $data['supplier_id'] = $supplierId;
        }
        if (isset($arguments['branch_id']) || isset($arguments['branch']) || isset($arguments['branch_name'])) {
            $data['branch_id'] = $this->resolveBranchIdForCreate($user, $arguments);
        }

        if ($data === []) {
            return $this->clarification($user, 'ما الذي تريد تعديله في أمر الشراء؟', 'What would you like to change in the purchase order?');
        }

        $validator = Validator::make($data, [
            'supplier_id' => ['sometimes', Rule::exists('suppliers', 'id')->where(fn ($q) => $q->where('tenant_id', $user->tenant_id))],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', $user->tenant_id))],
            'order_date' => ['sometimes', 'date'],
            'expected_date' => ['nullable', 'date'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', Rule::exists('products', 'id')->where(fn ($q) => $q->where('tenant_id', $user->tenant_id))],
            'items.*.quantity' => ['required_with:items.*.product_id', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required_with:items.*.product_id', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل أمر الشراء: '.$validator->errors()->first(), 'Could not update the purchase order: '.$validator->errors()->first());
        }

        $updated = $this->purchaseOrderService->update($model, $validator->validated(), (int) $user->tenant_id);
        $summary = $this->loc($user, "تم تعديل أمر الشراء {$updated->purchase_number}.", "Purchase order {$updated->purchase_number} was updated.");
        $this->recordAudit($user, 'assistant.purchase.update', $updated, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => PurchaseOrderResource::make($updated)->resolve()];
    }

    private function deletePurchase(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }

        $resolved = $this->resolvePurchaseOrderTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var PurchaseOrder $model */
        $model = $resolved['model'];
        $purchaseNumber = $model->purchase_number;
        $deletedId = $model->id;
        $this->purchaseOrderService->deleteIfAllowed($model);
        $summary = $this->loc($user, "تم حذف أمر الشراء {$purchaseNumber}.", "Purchase order {$purchaseNumber} was deleted.");
        $this->recordAudit($user, 'assistant.purchase.delete', $model, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $deletedId]];
    }

    private function queryContracts(User $user, ?string $target, array $arguments): array
    {
        $search = $target ?: ($arguments['search'] ?? null);
        $limit = $this->limitFromArguments($arguments);
        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);

        $items = InstallmentContract::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['customer', 'branch', 'order'])
            ->when($search, fn ($q) => $q->where(fn ($nested) => $nested
                ->where('contract_number', 'like', '%'.$search.'%')
                ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', '%'.$search.'%'))))
            ->when(isset($arguments['status']), fn ($q) => $q->where('status', $arguments['status']))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->latest()
            ->limit($limit)
            ->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$items->count().' عقد.', 'Found '.$items->count().' contracts.'),
            'data' => [
                'items' => ContractResource::collection($items)->resolve(),
                'count' => $items->count(),
            ],
        ];
    }

    private function createContract(User $user, array $arguments, string $channel): array
    {
        $data = [
            'order_id' => $arguments['order_id'] ?? $arguments['order'] ?? null,
            'down_payment' => $arguments['down_payment'] ?? null,
            'monthly_amount' => $arguments['monthly_amount'] ?? $arguments['monthly_installment'] ?? null,
            'duration_months' => $arguments['duration_months'] ?? $arguments['duration'] ?? null,
            'start_date' => $arguments['start_date'] ?? now()->toDateString(),
            'first_due_date' => $arguments['first_due_date'] ?? null,
            'notes' => $arguments['notes'] ?? null,
        ];

        if ($data['order_id'] === null) {
            return $this->clarification($user, 'حدد الطلب الذي تريد تحويله إلى عقد.', 'Specify the order you want to convert into a contract.');
        }
        if ($data['down_payment'] === null) {
            return $this->clarification($user, 'ما هي الدفعة الأولى للعقد؟', 'What is the contract down payment?');
        }
        if ($data['duration_months'] === null) {
            return $this->clarification($user, 'ما هي مدة العقد بالأشهر؟', 'What is the contract duration in months?');
        }
        if ($data['first_due_date'] === null) {
            return $this->clarification($user, 'ما هو تاريخ أول استحقاق؟', 'What is the first due date?');
        }

        $validator = Validator::make($data, [
            'order_id' => ['required', 'exists:orders,id'],
            'down_payment' => ['required', 'numeric', 'min:0'],
            'monthly_amount' => ['nullable', 'numeric', 'min:0.01'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:60'],
            'start_date' => ['required', 'date'],
            'first_due_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات العقد غير مكتملة أو غير صحيحة: '.$validator->errors()->first(), 'Contract data is incomplete or invalid: '.$validator->errors()->first());
        }

        $order = Order::query()->findOrFail((int) $validator->validated()['order_id']);
        $contract = $this->contractService->createFromOrder($order, $validator->validated(), $user);
        $summary = $this->loc($user, "تم إنشاء العقد {$contract->contract_number}.", "Contract {$contract->contract_number} was created.");
        $this->recordAudit($user, 'assistant.contract.create', $contract, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => ContractResource::make($contract)->resolve()];
    }

    private function queryPayments(User $user, ?string $target, array $arguments): array
    {
        $limit = $this->limitFromArguments($arguments);
        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);

        if (($arguments['view'] ?? null) === 'due_today') {
            $items = InstallmentSchedule::query()
                ->where('tenant_id', $user->tenant_id)
                ->whereDate('due_date', today())
                ->whereIn('status', ['upcoming', 'due_today', 'partial'])
                ->when($branchId !== null, fn ($q) => $q->whereHas('contract', fn ($cq) => $cq->where('branch_id', $branchId)))
                ->with(['contract.customer', 'contract.branch'])
                ->limit($limit)
                ->get();

            return ['status' => 'completed', 'summary' => $this->loc($user, 'هذه الأقساط المستحقة اليوم.', 'These are the installments due today.'), 'data' => ['items' => $items->toArray(), 'count' => $items->count()]];
        }

        if (($arguments['view'] ?? null) === 'overdue') {
            $items = InstallmentSchedule::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('status', 'overdue')
                ->when($branchId !== null, fn ($q) => $q->whereHas('contract', fn ($cq) => $cq->where('branch_id', $branchId)))
                ->with(['contract.customer', 'contract.branch'])
                ->orderBy('due_date')
                ->limit($limit)
                ->get();

            return ['status' => 'completed', 'summary' => $this->loc($user, 'هذه الأقساط المتأخرة.', 'These are the overdue installments.'), 'data' => ['items' => $items->toArray(), 'count' => $items->count()]];
        }

        $items = Payment::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['customer', 'contract', 'collectedBy', 'branch'])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when(isset($arguments['customer_id']), fn ($q) => $q->where('customer_id', $arguments['customer_id']))
            ->when(isset($arguments['contract_id']), fn ($q) => $q->where('contract_id', $arguments['contract_id']))
            ->when(isset($arguments['date_from']), fn ($q) => $q->whereDate('payment_date', '>=', $arguments['date_from']))
            ->when(isset($arguments['date_to']), fn ($q) => $q->whereDate('payment_date', '<=', $arguments['date_to']))
            ->latest()
            ->limit($limit)
            ->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$items->count().' دفعة.', 'Found '.$items->count().' payments.'),
            'data' => [
                'items' => PaymentResource::collection($items)->resolve(),
                'count' => $items->count(),
            ],
        ];
    }

    private function createPayment(User $user, array $arguments, string $channel): array
    {
        $contractId = $arguments['contract_id'] ?? $arguments['contract'] ?? null;
        if ($contractId === null) {
            return $this->clarification($user, 'حدد العقد الذي تريد تسجيل الدفعة عليه.', 'Specify the contract you want to record the payment for.');
        }
        if (! isset($arguments['amount'])) {
            return $this->clarification($user, 'ما هو مبلغ الدفعة؟', 'What is the payment amount?');
        }

        $contractResolved = $this->resolveContractTarget($user, $contractId);
        if ($contractResolved['status'] !== 'resolved') {
            return $contractResolved['response'];
        }

        /** @var InstallmentContract $contract */
        $contract = $contractResolved['model'];
        $data = [
            'amount' => $arguments['amount'],
            'payment_method' => $arguments['payment_method'] ?? 'cash',
            'payment_date' => $arguments['payment_date'] ?? now()->toDateString(),
            'reference_number' => $arguments['reference_number'] ?? null,
            'collector_notes' => $arguments['collector_notes'] ?? ($arguments['notes'] ?? null),
            'schedule_id' => $arguments['schedule_id'] ?? null,
            'cashbox_id' => $arguments['cashbox_id'] ?? null,
        ];

        $validator = Validator::make($data, [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'cheque', 'card', 'other'])],
            'payment_date' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'collector_notes' => ['nullable', 'string', 'max:2000'],
            'schedule_id' => ['nullable', 'integer'],
            'cashbox_id' => ['nullable', Rule::exists('cashboxes', 'id')->where(fn ($q) => $q->where('tenant_id', $user->tenant_id))],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات الدفعة غير مكتملة أو غير صحيحة: '.$validator->errors()->first(), 'Payment data is incomplete or invalid: '.$validator->errors()->first());
        }

        $payment = $this->paymentService->record($contract, $validator->validated(), (int) $user->id);
        $summary = $this->loc($user, "تم تسجيل الدفعة {$payment->receipt_number}.", "Payment {$payment->receipt_number} was recorded.");
        $this->recordAudit($user, 'assistant.payment.create', $payment, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => PaymentResource::make($payment)->resolve()];
    }

    private function queryInvoices(User $user, ?string $target, array $arguments): array
    {
        $limit = $this->limitFromArguments($arguments);
        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);

        $items = Invoice::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['customer', 'branch', 'order'])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when(isset($arguments['status']), fn ($q) => $q->where('status', $arguments['status']))
            ->when(isset($arguments['type']), fn ($q) => $q->where('type', $arguments['type']))
            ->when($target, fn ($q) => $q->where('invoice_number', 'like', '%'.$target.'%'))
            ->latest()
            ->limit($limit)
            ->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$items->count().' فاتورة.', 'Found '.$items->count().' invoices.'),
            'data' => [
                'items' => InvoiceResource::collection($items)->resolve(),
                'count' => $items->count(),
            ],
        ];
    }

    private function updateInvoice(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolveInvoiceTarget($user, $target ?? ($arguments['id'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Invoice $invoice */
        $invoice = $resolved['model'];

        if (isset($arguments['amount'])) {
            $data = [
                'amount' => $arguments['amount'],
                'payment_method' => $arguments['payment_method'] ?? 'cash',
                'payment_date' => $arguments['payment_date'] ?? now()->toDateString(),
                'reference_number' => $arguments['reference_number'] ?? null,
                'collector_notes' => $arguments['collector_notes'] ?? ($arguments['notes'] ?? null),
                'cashbox_id' => $arguments['cashbox_id'] ?? null,
            ];

            $validator = Validator::make($data, [
                'amount' => ['required', 'numeric', 'min:0.01'],
                'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'cheque', 'card', 'other'])],
                'payment_date' => ['nullable', 'date'],
                'reference_number' => ['nullable', 'string', 'max:255'],
                'collector_notes' => ['nullable', 'string', 'max:2000'],
                'cashbox_id' => ['nullable', Rule::exists('cashboxes', 'id')->where(fn ($q) => $q->where('tenant_id', $user->tenant_id))],
            ]);

            if ($validator->fails()) {
                return $this->clarification($user, 'بيانات سداد الفاتورة غير مكتملة أو غير صحيحة: '.$validator->errors()->first(), 'Invoice payment data is incomplete or invalid: '.$validator->errors()->first());
            }

            $payment = $this->invoiceService->recordPayment($invoice, $validator->validated(), (int) $user->id);
            $invoice->refresh()->load(['customer', 'branch', 'order', 'items', 'payments']);
            $summary = $this->loc($user, "تم تسجيل دفعة على الفاتورة {$invoice->invoice_number}.", "Recorded a payment on invoice {$invoice->invoice_number}.");
            $this->recordAudit($user, 'assistant.invoice.record_payment', $invoice, ['channel' => $channel, 'payment_id' => $payment->id], $summary);

            return ['status' => 'completed', 'summary' => $summary, 'data' => ['invoice' => InvoiceResource::make($invoice)->resolve(), 'payment' => PaymentResource::make($payment)->resolve()]];
        }

        if (($arguments['status'] ?? null) === 'cancelled' || ($arguments['cancel'] ?? false) === true) {
            $invoice = $this->invoiceService->cancel($invoice);
            $summary = $this->loc($user, "تم إلغاء الفاتورة {$invoice->invoice_number}.", "Invoice {$invoice->invoice_number} was cancelled.");
            $this->recordAudit($user, 'assistant.invoice.cancel', $invoice, ['channel' => $channel], $summary);

            return ['status' => 'completed', 'summary' => $summary, 'data' => InvoiceResource::make($invoice->fresh(['customer', 'branch', 'order']))->resolve()];
        }

        return $this->clarification($user, 'يمكنني إما تسجيل دفعة على الفاتورة أو إلغاؤها. حدّد العملية المطلوبة.', 'I can either record a payment on the invoice or cancel it. Please specify the intended action.');
    }

    private function queryCashboxes(User $user, ?string $target, array $arguments): array
    {
        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);
        $items = Cashbox::query()
            ->where('tenant_id', $user->tenant_id)
            ->with('branch')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($target, fn ($q) => $q->where('name', 'like', '%'.$target.'%'))
            ->orderBy('name')
            ->limit($this->limitFromArguments($arguments))
            ->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$items->count().' صندوق.', 'Found '.$items->count().' cashboxes.'),
            'data' => [
                'items' => CashboxResource::collection($items)->resolve(),
                'count' => $items->count(),
            ],
        ];
    }

    private function createCashbox(User $user, array $arguments, string $channel): array
    {
        $branchId = $this->resolveBranchIdForCreate($user, $arguments);
        if ($branchId === null) {
            return $this->clarification($user, 'حدد الفرع الذي تريد إنشاء الصندوق له.', 'Specify the branch where you want to create the cashbox.');
        }

        $data = [
            'branch_id' => $branchId,
            'name' => $arguments['name'] ?? null,
            'type' => $arguments['type'] ?? null,
            'is_primary' => $arguments['is_primary'] ?? null,
            'opening_balance' => $arguments['opening_balance'] ?? 0,
            'is_active' => $arguments['is_active'] ?? true,
        ];

        if (blank($data['name'])) {
            return $this->clarification($user, 'ما هو اسم الصندوق؟', 'What is the cashbox name?');
        }

        $validator = Validator::make($data, [
            'branch_id' => ['required', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', $user->tenant_id))],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:64'],
            'is_primary' => ['nullable', 'boolean'],
            'opening_balance' => ['nullable', 'numeric'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات الصندوق غير مكتملة أو غير صحيحة: '.$validator->errors()->first(), 'Cashbox data is incomplete or invalid: '.$validator->errors()->first());
        }

        $validated = $validator->validated();
        $cashbox = Cashbox::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $validated['branch_id'],
            'name' => $validated['name'],
            'type' => $validated['type'] ?? null,
            'opening_balance' => (float) ($validated['opening_balance'] ?? 0),
            'current_balance' => (float) ($validated['opening_balance'] ?? 0),
            'is_active' => $validated['is_active'] ?? true,
            'is_primary' => (bool) ($validated['is_primary'] ?? false),
        ]);

        $summary = $this->loc($user, "تم إنشاء الصندوق {$cashbox->name}.", "Cashbox {$cashbox->name} was created.");
        $this->recordAudit($user, 'assistant.cashbox.create', $cashbox, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => CashboxResource::make($cashbox->load('branch'))->resolve()];
    }

    private function updateCashbox(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolveCashboxTarget($user, $target ?? ($arguments['id'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Cashbox $model */
        $model = $resolved['model'];
        $data = array_intersect_key($arguments, array_flip(['name', 'type', 'is_primary', 'is_active']));
        if ($data === []) {
            return $this->clarification($user, 'ما الذي تريد تعديله في الصندوق؟', 'What would you like to change in the cashbox?');
        }

        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:64'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل الصندوق: '.$validator->errors()->first(), 'Could not update the cashbox: '.$validator->errors()->first());
        }

        $model->update($validator->validated());
        $summary = $this->loc($user, "تم تعديل الصندوق {$model->name}.", "Cashbox {$model->name} was updated.");
        $this->recordAudit($user, 'assistant.cashbox.update', $model, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => CashboxResource::make($model->fresh()->load('branch'))->resolve()];
    }

    private function deleteCashbox(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }

        $resolved = $this->resolveCashboxTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Cashbox $model */
        $model = $resolved['model'];
        if ($model->cashTransactions()->exists()) {
            return $this->rejected($user, 'لا يمكن حذف صندوق يحتوي على حركات.', 'Cannot delete a cashbox that has transactions.');
        }

        $summary = $this->loc($user, "تم حذف الصندوق {$model->name}.", "Cashbox {$model->name} was deleted.");
        $this->recordAudit($user, 'assistant.cashbox.delete', $model, ['channel' => $channel], $summary);
        $model->delete();

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $model->id]];
    }

    private function queryExpenses(User $user, ?string $target, array $arguments): array
    {
        $limit = $this->limitFromArguments($arguments);
        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);

        $items = Expense::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['branch', 'cashbox', 'createdBy'])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($target, fn ($q) => $q->where(fn ($nested) => $nested
                ->where('expense_number', 'like', '%'.$target.'%')
                ->orWhere('category', 'like', '%'.$target.'%')
                ->orWhere('vendor_name', 'like', '%'.$target.'%')))
            ->when(isset($arguments['category']), fn ($q) => $q->where('category', $arguments['category']))
            ->when(isset($arguments['status']), fn ($q) => $q->where('status', $arguments['status']))
            ->latest('expense_date')
            ->limit($limit)
            ->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$items->count().' مصروف.', 'Found '.$items->count().' expenses.'),
            'data' => [
                'items' => ExpenseResource::collection($items)->resolve(),
                'count' => $items->count(),
            ],
        ];
    }

    private function createExpense(User $user, array $arguments, string $channel): array
    {
        $branchId = $this->resolveBranchIdForCreate($user, $arguments);
        if ($branchId === null) {
            return $this->clarification($user, 'حدد الفرع الخاص بالمصروف.', 'Specify the branch for the expense.');
        }

        $data = [
            'branch_id' => $branchId,
            'cashbox_id' => $arguments['cashbox_id'] ?? null,
            'category' => $arguments['category'] ?? null,
            'amount' => $arguments['amount'] ?? null,
            'expense_date' => $arguments['expense_date'] ?? now()->toDateString(),
            'vendor_name' => $arguments['vendor_name'] ?? null,
            'notes' => $arguments['notes'] ?? null,
        ];

        if (blank($data['category'])) {
            return $this->clarification($user, 'ما هو تصنيف المصروف؟', 'What is the expense category?');
        }
        if ($data['amount'] === null) {
            return $this->clarification($user, 'ما هو مبلغ المصروف؟', 'What is the expense amount?');
        }

        $validator = Validator::make($data, [
            'branch_id' => ['required', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', $user->tenant_id))],
            'cashbox_id' => ['nullable', Rule::exists('cashboxes', 'id')->where(fn ($q) => $q->where('tenant_id', $user->tenant_id))],
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['required', 'date'],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات المصروف غير مكتملة أو غير صحيحة: '.$validator->errors()->first(), 'Expense data is incomplete or invalid: '.$validator->errors()->first());
        }

        $expense = $this->expenseService->create($validator->validated(), (int) $user->tenant_id, (int) $user->id);
        $summary = $this->loc($user, "تم تسجيل المصروف {$expense->expense_number}.", "Expense {$expense->expense_number} was recorded.");
        $this->recordAudit($user, 'assistant.expense.create', $expense, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => ExpenseResource::make($expense)->resolve()];
    }

    private function updateExpense(User $user, ?string $target, array $arguments, string $channel): array
    {
        $resolved = $this->resolveExpenseTarget($user, $target ?? ($arguments['id'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Expense $expense */
        $expense = $resolved['model'];

        if (($arguments['status'] ?? null) === 'cancelled' || ($arguments['cancel'] ?? false) === true) {
            $expense = $this->expenseService->cancel($expense, (int) $user->id);
            $summary = $this->loc($user, "تم إلغاء المصروف {$expense->expense_number}.", "Expense {$expense->expense_number} was cancelled.");
            $this->recordAudit($user, 'assistant.expense.cancel', $expense, ['channel' => $channel], $summary);

            return ['status' => 'completed', 'summary' => $summary, 'data' => ExpenseResource::make($expense)->resolve()];
        }

        $data = array_intersect_key($arguments, array_flip(['category', 'vendor_name', 'notes', 'expense_date']));
        if ($data === []) {
            return $this->clarification($user, 'ما الذي تريد تعديله في المصروف؟', 'What would you like to change in the expense?');
        }

        $validator = Validator::make($data, [
            'category' => ['sometimes', 'string', 'max:100'],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'expense_date' => ['sometimes', 'date'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'تعذر تعديل المصروف: '.$validator->errors()->first(), 'Could not update the expense: '.$validator->errors()->first());
        }

        $expense = $this->expenseService->updateMetadata($expense, $validator->validated());
        $summary = $this->loc($user, "تم تعديل المصروف {$expense->expense_number}.", "Expense {$expense->expense_number} was updated.");
        $this->recordAudit($user, 'assistant.expense.update', $expense, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => ExpenseResource::make($expense)->resolve()];
    }

    private function deleteExpense(User $user, ?string $target, string $channel, bool $confirmedDelete): array
    {
        if (! $confirmedDelete) {
            return $this->clarification($user, 'تأكيد الحذف مطلوب.', 'Delete confirmation is required.');
        }

        $resolved = $this->resolveExpenseTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Expense $expense */
        $expense = $resolved['model'];
        $expenseNumber = $expense->expense_number;
        $deletedId = $expense->id;
        $this->expenseService->deleteIfAllowed($expense);
        $summary = $this->loc($user, "تم حذف المصروف {$expenseNumber}.", "Expense {$expenseNumber} was deleted.");
        $this->recordAudit($user, 'assistant.expense.delete', $expense, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['deleted_id' => $deletedId]];
    }

    private function queryCashTransactions(User $user, ?string $target, array $arguments): array
    {
        $limit = $this->limitFromArguments($arguments);
        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);

        $items = CashTransaction::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['cashbox', 'branch', 'createdBy'])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($target, fn ($q) => $q->where(fn ($nested) => $nested
                ->where('voucher_number', 'like', '%'.$target.'%')
                ->orWhere('notes', 'like', '%'.$target.'%')))
            ->when(isset($arguments['direction']), fn ($q) => $q->where('direction', $arguments['direction']))
            ->when(isset($arguments['transaction_type']), fn ($q) => $q->where('transaction_type', $arguments['transaction_type']))
            ->latest('transaction_date')
            ->limit($limit)
            ->get();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم العثور على '.$items->count().' حركة صندوق.', 'Found '.$items->count().' cash transactions.'),
            'data' => [
                'items' => CashTransactionResource::collection($items)->resolve(),
                'count' => $items->count(),
            ],
        ];
    }

    private function printCustomerStatement(User $user, ?string $target, array $arguments): array
    {
        $resolved = $this->resolveCustomerTarget($user, $target ?? ($arguments['customer_id'] ?? $arguments['customer'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        return $this->printDocumentResponse($user, 'كشف حساب العميل جاهز للطباعة PDF.', 'Customer statement is ready as a PDF.', 'customer_statement', (int) $resolved['model']->id, '/print/statement/'.$resolved['model']->id, 'statement-'.$resolved['model']->id.'.pdf');
    }

    private function printContract(User $user, ?string $target, array $arguments): array
    {
        $resolved = $this->resolveContractTarget($user, $target ?? ($arguments['contract_id'] ?? $arguments['contract'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        return $this->printDocumentResponse($user, 'العقد جاهز للطباعة PDF.', 'The contract is ready as a PDF.', 'contract', (int) $resolved['model']->id, '/print/contract/'.$resolved['model']->id, 'contract-'.$resolved['model']->id.'.pdf');
    }

    private function printInvoice(User $user, ?string $target, array $arguments): array
    {
        $resolved = $this->resolveInvoiceTarget($user, $target ?? ($arguments['invoice_id'] ?? $arguments['invoice'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        return $this->printDocumentResponse($user, 'الفاتورة جاهزة للطباعة PDF.', 'The invoice is ready as a PDF.', 'invoice', (int) $resolved['model']->id, '/print/invoice/'.$resolved['model']->id, 'invoice-'.$resolved['model']->id.'.pdf');
    }

    private function printPaymentReceipt(User $user, ?string $target, array $arguments): array
    {
        $paymentId = $target ?? ($arguments['payment_id'] ?? $arguments['payment'] ?? null);
        if ($paymentId === null) {
            return $this->clarification($user, 'حدد الدفعة أو الإيصال الذي تريد طباعته.', 'Specify the payment or receipt you want to print.');
        }

        $payment = Payment::query()
            ->where('tenant_id', $user->tenant_id)
            ->when(TenantBranchScope::isBranchScoped($user), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when(is_numeric($paymentId), fn ($q) => $q->whereKey((int) $paymentId), fn ($q) => $q->where('receipt_number', 'like', '%'.$paymentId.'%'))
            ->first();

        if (! $payment) {
            return $this->clarification($user, 'لم أجد الدفعة المطلوبة للطباعة.', 'I could not find the payment you want to print.');
        }

        return $this->printDocumentResponse($user, 'الإيصال جاهز للطباعة PDF.', 'The payment receipt is ready as a PDF.', 'payment_receipt', (int) $payment->id, '/print/payment/'.$payment->id, 'payment-'.$payment->id.'.pdf');
    }

    private function printPurchaseOrder(User $user, ?string $target, array $arguments): array
    {
        $resolved = $this->resolvePurchaseOrderTarget($user, $target ?? ($arguments['purchase_id'] ?? $arguments['purchase_order_id'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        return $this->printDocumentResponse($user, 'أمر الشراء جاهز للطباعة PDF.', 'The purchase order is ready as a PDF.', 'purchase_order', (int) $resolved['model']->id, '/print/purchase-order/'.$resolved['model']->id, 'purchase-order-'.$resolved['model']->id.'.pdf');
    }

    private function printCashVoucher(User $user, ?string $target, array $arguments): array
    {
        $transactionId = $target ?? ($arguments['cash_transaction_id'] ?? $arguments['transaction_id'] ?? $arguments['voucher'] ?? null);
        if ($transactionId === null) {
            return $this->clarification($user, 'حدد حركة الصندوق أو رقم السند الذي تريد طباعته.', 'Specify the cash transaction or voucher number you want to print.');
        }

        $query = CashTransaction::query()->where('tenant_id', $user->tenant_id);
        if (TenantBranchScope::isBranchScoped($user)) {
            $query->where('branch_id', $user->branch_id);
        }

        $transaction = is_numeric($transactionId)
            ? (clone $query)->find((int) $transactionId)
            : (clone $query)->where('voucher_number', 'like', '%'.$transactionId.'%')->first();

        if (! $transaction) {
            return $this->clarification($user, 'لم أجد حركة الصندوق المطلوبة للطباعة.', 'I could not find the cash transaction you want to print.');
        }

        return $this->printDocumentResponse($user, 'سند الصندوق جاهز للطباعة PDF.', 'The cash voucher is ready as a PDF.', 'cash_voucher', (int) $transaction->id, '/cash/voucher/'.$transaction->id, 'voucher-'.$transaction->id.'.pdf');
    }

    private function printDocumentResponse(User $user, string $arSummary, string $enSummary, string $type, int $recordId, string $path, string $filename): array
    {
        $baseUrl = $this->frontendBaseUrl();
        $downloadUrl = $this->assistantPdfService->temporarySignedUrl($user, $type, $recordId, $filename);

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, $arSummary, $enSummary),
            'data' => [
                'print_document' => [
                    'type' => $type,
                    'record_id' => $recordId,
                    'path' => $path,
                    'url' => $baseUrl.$path,
                    'download_path' => $downloadUrl,
                    'download_url' => $downloadUrl,
                    'telegram_document_url' => $downloadUrl,
                    'filename' => $filename,
                ],
            ],
        ];
    }

    private function frontendBaseUrl(): string
    {
        $origins = config('cors.allowed_origins', []);
        $firstOrigin = is_array($origins) ? ($origins[0] ?? null) : null;
        $base = is_string($firstOrigin) && $firstOrigin !== '' ? $firstOrigin : config('app.url');

        return rtrim((string) $base, '/');
    }

    private function resolveCustomerTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد العميل المقصود بالاسم أو الرقم.', 'Specify the customer by name or ID.')];
        }

        $query = Customer::query()->where('tenant_id', $user->tenant_id);
        if (TenantBranchScope::isBranchScoped($user)) {
            $query->where('branch_id', $user->branch_id);
        }

        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)
            ->where(fn ($q) => $q->where('name', 'like', '%'.$target.'%')->orWhere('phone', 'like', '%'.$target.'%')->orWhere('email', 'like', '%'.$target.'%')->orWhere('national_id', 'like', '%'.$target.'%'))
            ->limit(3)
            ->get();

        return $this->resolveMatchSet($user, $matches, 'عميل', 'customer');
    }

    private function resolveProductTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد المنتج المقصود بالاسم أو الرقم.', 'Specify the product by name or ID.')];
        }

        $query = Product::query()->where('tenant_id', $user->tenant_id);

        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)
            ->where(fn ($q) => $q
                ->where('name', 'like', '%'.$target.'%')
                ->orWhere('name_ar', 'like', '%'.$target.'%')
                ->orWhere('sku', 'like', '%'.$target.'%'))
            ->limit(3)
            ->get();

        return $this->resolveMatchSet($user, $matches, 'منتج', 'product');
    }

    private function resolveOrderTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد الطلب المقصود برقم الطلب أو المعرف.', 'Specify the order by order number or ID.')];
        }

        $query = Order::query()->where('tenant_id', $user->tenant_id);
        if (TenantBranchScope::isBranchScoped($user)) {
            $query->where('branch_id', $user->branch_id);
        }

        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)
            ->where(fn ($q) => $q
                ->where('order_number', 'like', '%'.$target.'%')
                ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', '%'.$target.'%')))
            ->limit(3)
            ->get();

        return $this->resolveMatchSet($user, $matches, 'طلب', 'order');
    }

    private function resolveUserTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد المستخدم المقصود بالاسم أو البريد أو الرقم.', 'Specify the user by name, email, or ID.')];
        }

        $query = User::query()->where('tenant_id', $user->tenant_id);
        if (TenantBranchScope::isBranchScoped($user)) {
            $query->where('branch_id', $user->branch_id);
        }

        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)
            ->where(fn ($q) => $q
                ->where('name', 'like', '%'.$target.'%')
                ->orWhere('email', 'like', '%'.$target.'%')
                ->orWhere('phone', 'like', '%'.$target.'%'))
            ->limit(3)
            ->get();

        return $this->resolveMatchSet($user, $matches, 'مستخدم', 'user');
    }

    private function resolveBranchTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد الفرع المقصود بالاسم أو الرمز أو الرقم.', 'Specify the branch by name, code, or ID.')];
        }

        $query = Branch::query()->where('tenant_id', $user->tenant_id);
        if (TenantBranchScope::isBranchScoped($user)) {
            $query->where('id', $user->branch_id);
        }

        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)
            ->where(fn ($q) => $q
                ->where('name', 'like', '%'.$target.'%')
                ->orWhere('code', 'like', '%'.$target.'%')
                ->orWhere('city', 'like', '%'.$target.'%'))
            ->limit(3)
            ->get();

        return $this->resolveMatchSet($user, $matches, 'فرع', 'branch');
    }

    private function resolveSupplierTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد المورد المقصود بالاسم أو الرقم.', 'Specify the supplier by name or ID.')];
        }

        $query = Supplier::query()->where('tenant_id', $user->tenant_id);

        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)
            ->where(fn ($q) => $q
                ->where('name', 'like', '%'.$target.'%')
                ->orWhere('phone', 'like', '%'.$target.'%')
                ->orWhere('email', 'like', '%'.$target.'%'))
            ->limit(3)
            ->get();

        return $this->resolveMatchSet($user, $matches, 'مورد', 'supplier');
    }

    private function resolvePurchaseOrderTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد أمر الشراء برقم الأمر أو المعرّف.', 'Specify the purchase order by number or ID.')];
        }

        $query = PurchaseOrder::query()->where('tenant_id', $user->tenant_id);
        if (TenantBranchScope::isBranchScoped($user)) {
            $query->where('branch_id', $user->branch_id);
        }

        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)
            ->where('purchase_number', 'like', '%'.$target.'%')
            ->limit(3)
            ->get();

        return $this->resolveMatchSet($user, $matches, 'أمر شراء', 'purchase order');
    }

    private function resolveContractTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد العقد المقصود برقم العقد أو المعرّف.', 'Specify the contract by contract number or ID.')];
        }

        $query = InstallmentContract::query()->where('tenant_id', $user->tenant_id);
        if (TenantBranchScope::isBranchScoped($user)) {
            $query->where('branch_id', $user->branch_id);
        }

        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)
            ->where(fn ($q) => $q
                ->where('contract_number', 'like', '%'.$target.'%')
                ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', '%'.$target.'%')))
            ->limit(3)
            ->get();

        return $this->resolveMatchSet($user, $matches, 'عقد', 'contract');
    }

    private function resolveInvoiceTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد الفاتورة برقم الفاتورة أو المعرّف.', 'Specify the invoice by invoice number or ID.')];
        }

        $query = Invoice::query()->where('tenant_id', $user->tenant_id);
        if (TenantBranchScope::isBranchScoped($user)) {
            $query->where('branch_id', $user->branch_id);
        }

        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)
            ->where('invoice_number', 'like', '%'.$target.'%')
            ->limit(3)
            ->get();

        return $this->resolveMatchSet($user, $matches, 'فاتورة', 'invoice');
    }

    private function resolveCashboxTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد الصندوق بالاسم أو المعرّف.', 'Specify the cashbox by name or ID.')];
        }

        $query = Cashbox::query()->where('tenant_id', $user->tenant_id);
        if (TenantBranchScope::isBranchScoped($user)) {
            $query->where('branch_id', $user->branch_id);
        }

        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)->where('name', 'like', '%'.$target.'%')->limit(3)->get();

        return $this->resolveMatchSet($user, $matches, 'صندوق', 'cashbox');
    }

    private function resolveExpenseTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد المصروف برقم السند أو المعرّف أو التصنيف.', 'Specify the expense by number, ID, or category.')];
        }

        $query = Expense::query()->where('tenant_id', $user->tenant_id);
        if (TenantBranchScope::isBranchScoped($user)) {
            $query->where('branch_id', $user->branch_id);
        }

        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)
            ->where(fn ($q) => $q
                ->where('expense_number', 'like', '%'.$target.'%')
                ->orWhere('category', 'like', '%'.$target.'%')
                ->orWhere('vendor_name', 'like', '%'.$target.'%'))
            ->limit(3)
            ->get();

        return $this->resolveMatchSet($user, $matches, 'مصروف', 'expense');
    }

    private function resolveMatchSet(User $user, $matches, string $labelAr, string $labelEn): array
    {
        if ($matches->count() === 1) {
            return ['status' => 'resolved', 'model' => $matches->first()];
        }

        if ($matches->isEmpty()) {
            return [
                'status' => 'not_found',
                'response' => $this->clarification(
                    $user,
                    "لم أجد {$labelAr} مطابقاً. جرّب الاسم الكامل أو المعرّف.",
                    "I could not find a matching {$labelEn}. Try the full name or ID."
                ),
            ];
        }

        $options = $matches->values()->map(function ($item, int $index) {
            return [
                'number' => $index + 1,
                'value' => (string) $item->id,
                'label' => $this->modelChoiceLabel($item),
            ];
        })->all();

        $optionsAr = collect($options)->map(fn (array $option) => $option['number'].') '.$option['label'])->implode('، ');
        $optionsEn = collect($options)->map(fn (array $option) => $option['number'].') '.$option['label'])->implode(', ');

        return [
            'status' => 'ambiguous',
            'response' => $this->clarification(
                $user,
                "وجدت أكثر من {$labelAr}. اختر رقم الخيار المناسب: {$optionsAr}",
                "I found more than one matching {$labelEn}. Choose the option number: {$optionsEn}",
                [
                    'clarification' => [
                        'kind' => 'selection',
                        'field' => 'target',
                        'allow_none' => false,
                        'options' => $options,
                    ],
                ]
            ),
        ];
    }

    private function modelChoiceLabel(mixed $model): string
    {
        $parts = array_filter([
            $model->name ?? $model->contract_number ?? $model->invoice_number ?? $model->receipt_number ?? $model->purchase_number ?? $model->order_number ?? $model->expense_number ?? $model->code ?? null,
            isset($model->id) ? '#'.$model->id : null,
        ]);

        return implode(' ', $parts);
    }

    private function resolveCustomerIdFromArguments(User $user, array $arguments): int|array
    {
        $target = $arguments['customer_id'] ?? $arguments['customer'] ?? $arguments['customer_name'] ?? null;
        $resolved = $this->resolveCustomerTarget($user, $target);

        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        return (int) $resolved['model']->id;
    }

    private function resolveSupplierIdFromArguments(User $user, array $arguments): int|array
    {
        $target = $arguments['supplier_id'] ?? $arguments['supplier'] ?? $arguments['supplier_name'] ?? null;
        $resolved = $this->resolveSupplierTarget($user, $target);

        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        return (int) $resolved['model']->id;
    }

    private function resolveBranchIdForUser(User $user, ?int $requestedBranchId = null): ?int
    {
        if (TenantBranchScope::isBranchScoped($user)) {
            return $user->branch_id ? (int) $user->branch_id : null;
        }

        if ($requestedBranchId === null) {
            return null;
        }

        $exists = Branch::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereKey($requestedBranchId)
            ->exists();

        return $exists ? $requestedBranchId : null;
    }

    private function resolveBranchIdForCreate(User $user, array $arguments): ?int
    {
        if (TenantBranchScope::isBranchScoped($user)) {
            return $user->branch_id ? (int) $user->branch_id : null;
        }

        $branchTarget = $arguments['branch_id'] ?? ($arguments['branch_name'] ?? $arguments['branch'] ?? $user->branch_id);
        if ($branchTarget === null || $branchTarget === '') {
            return $user->branch_id ? (int) $user->branch_id : null;
        }

        $query = Branch::query()->where('tenant_id', $user->tenant_id);

        if (is_numeric($branchTarget)) {
            $branch = (clone $query)->find($branchTarget);

            return $branch ? (int) $branch->id : null;
        }

        $branchName = Str::lower(trim((string) $branchTarget));

        $branch = (clone $query)
            ->when(
                in_array($branchName, ['main', 'main branch', 'الرئيسي', 'الفرع الرئيسي'], true),
                fn ($builder) => $builder->where('is_main', true),
                fn ($builder) => $builder->where(fn ($nested) => $nested
                    ->where('name', 'like', '%'.$branchTarget.'%')
                    ->orWhere('code', 'like', '%'.$branchTarget.'%')
                    ->orWhere('city', 'like', '%'.$branchTarget.'%'))
            )
            ->first();

        return $branch ? (int) $branch->id : null;
    }

    private function normalizeOrderItems(array $items): array
    {
        return collect($items)
            ->filter(fn ($item) => is_array($item))
            ->map(fn ($item) => [
                'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : null,
                'quantity' => isset($item['quantity']) ? (int) $item['quantity'] : 1,
                'discount_amount' => isset($item['discount_amount']) ? (float) $item['discount_amount'] : 0,
                'serial_number' => $item['serial_number'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function buildOrderItemsPayload(User $user, array $items, string $type): array
    {
        if ($items === []) {
            return $this->clarification($user, 'أرسل عناصر الطلب مع product_id و quantity على الأقل.', 'Please provide order items with at least product_id and quantity.');
        }

        $payload = [];
        foreach ($items as $item) {
            if (empty($item['product_id'])) {
                return $this->clarification($user, 'كل عنصر في الطلب يحتاج product_id.', 'Every order item needs a product_id.');
            }

            $product = Product::query()
                ->where('tenant_id', $user->tenant_id)
                ->find($item['product_id']);

            if (! $product) {
                return $this->clarification($user, "المنتج {$item['product_id']} غير موجود.", "Product {$item['product_id']} was not found.");
            }

            $payload[] = [
                'product_id' => (int) $product->id,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'discount_amount' => (float) ($item['discount_amount'] ?? 0),
                'serial_number' => $item['serial_number'] ?? null,
            ];
        }

        return $payload;
    }

    private function limitFromArguments(array $arguments): int
    {
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 10;

        return max(1, min($limit, 50));
    }

    private function resolveReportPeriod(array $arguments, string $requestText): array
    {
        if (! empty($arguments['date_from']) && ! empty($arguments['date_to'])) {
            return [
                Carbon::parse($arguments['date_from'])->toDateString(),
                Carbon::parse($arguments['date_to'])->toDateString(),
            ];
        }

        $parsed = $this->parseMonthPeriodFromText($requestText);
        if ($parsed !== null) {
            return $parsed;
        }

        return [
            ! empty($arguments['date_from']) ? Carbon::parse($arguments['date_from'])->toDateString() : now()->startOfMonth()->toDateString(),
            ! empty($arguments['date_to']) ? Carbon::parse($arguments['date_to'])->toDateString() : now()->toDateString(),
        ];
    }

    private function parseMonthPeriodFromText(string $text): ?array
    {
        if (trim($text) === '') {
            return null;
        }

        $year = (int) now()->year;
        if (preg_match('/(?:سنة|عام|year)\s*[:#]?\s*(20\d{2})/iu', $text, $yearMatches) === 1) {
            $year = (int) $yearMatches[1];
        }

        if (preg_match('/(?:شهر|month)\s*[:#]?\s*(1[0-2]|0?[1-9])\b/iu', $text, $monthMatches) === 1) {
            $month = (int) $monthMatches[1];
            $from = Carbon::create($year, $month, 1)->startOfMonth();

            return [$from->toDateString(), $from->copy()->endOfMonth()->toDateString()];
        }

        $monthMap = [
            'يناير' => 1,
            'jan' => 1,
            'january' => 1,
            'فبراير' => 2,
            'feb' => 2,
            'february' => 2,
            'مارس' => 3,
            'march' => 3,
            'apr' => 4,
            'april' => 4,
            'ابريل' => 4,
            'أبريل' => 4,
            'مايو' => 5,
            'may' => 5,
            'يونيو' => 6,
            'june' => 6,
            'يوليو' => 7,
            'july' => 7,
            'اغسطس' => 8,
            'أغسطس' => 8,
            'aug' => 8,
            'august' => 8,
            'سبتمبر' => 9,
            'sep' => 9,
            'september' => 9,
            'أكتوبر' => 10,
            'اكتوبر' => 10,
            'oct' => 10,
            'october' => 10,
            'نوفمبر' => 11,
            'nov' => 11,
            'november' => 11,
            'ديسمبر' => 12,
            'dec' => 12,
            'december' => 12,
        ];

        $normalized = Str::lower($text);
        foreach ($monthMap as $token => $month) {
            if (! Str::contains($normalized, Str::lower($token))) {
                continue;
            }

            $from = Carbon::create($year, $month, 1)->startOfMonth();

            return [$from->toDateString(), $from->copy()->endOfMonth()->toDateString()];
        }

        return null;
    }

    private function reportNeedsDetails(array $arguments, string $requestText): bool
    {
        if (array_key_exists('details', $arguments)) {
            return filter_var($arguments['details'], FILTER_VALIDATE_BOOLEAN) || $arguments['details'] === true || $arguments['details'] === 1;
        }

        $normalized = Str::lower($requestText);

        return Str::contains($normalized, ['تفاصيل', 'بالتفصيل', 'تفصيلي', 'detailed', 'details']);
    }

    private function guardReadOnlySql(User $user, string $sql): ?string
    {
        $normalized = trim($sql);
        $normalized = preg_replace('/;\s*$/', '', $normalized) ?? $normalized;

        if (! preg_match('/^\s*(select|with)\b/i', $normalized)) {
            return $this->loc($user, 'الاستعلام يجب أن يبدأ بـ SELECT أو WITH فقط.', 'The query must start with SELECT or WITH only.');
        }

        if (str_contains($normalized, ';')) {
            return $this->loc($user, 'غير مسموح بأكثر من جملة SQL واحدة.', 'Only a single SQL statement is allowed.');
        }

        if (preg_match('/\b(insert|update|delete|drop|alter|truncate|create|replace|attach|detach|reindex|grant|revoke|begin|commit|rollback|call|exec|execute)\b/i', $normalized) === 1) {
            return $this->loc($user, 'تم اكتشاف كلمات محظورة تخص التعديل أو الإدارة.', 'Blocked write or admin keywords were detected.');
        }

        return null;
    }

    private function appendLimitToSql(string $sql, int $limit): string
    {
        $trimmed = preg_replace('/;\s*$/', '', trim($sql)) ?? trim($sql);

        if (preg_match('/\blimit\s+\d+\b/i', $trimmed) === 1) {
            return $trimmed;
        }

        return $trimmed.' LIMIT '.$limit;
    }

    private function normalizeReportType(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = Str::of($value)->trim()->lower()->replace(['-', ' '], '_')->value();

        return match (true) {
            Str::contains($normalized, ['sales', 'المبيعات', 'مبيعات']) => 'sales',
            Str::contains($normalized, ['collections', 'collection', 'التحصيل', 'التحصيلات']) => 'collections',
            Str::contains($normalized, ['active_contracts', 'العقود_النشطة', 'العقود النشطة']) => 'active_contracts',
            Str::contains($normalized, ['overdue', 'overdue_installments', 'المتأخرات', 'المتأخرات_المالية', 'المتأخرات المالية']) => 'overdue',
            Str::contains($normalized, ['branch_performance', 'اداء_الفروع', 'أداء_الفروع', 'أداء الفروع']) => 'branch_performance',
            Str::contains($normalized, ['agent_performance', 'اداء_الموظفين', 'أداء_الموظفين', 'اداء_المبيعات', 'أداء_المبيعات', 'أداء الموظفين']) => 'agent_performance',
            default => null,
        };
    }

    private function reportTypeLabel(string $type, string $locale): string
    {
        return match ($type) {
            'sales' => $locale === 'ar' ? 'المبيعات' : 'sales',
            'collections' => $locale === 'ar' ? 'التحصيل' : 'collections',
            'active_contracts' => $locale === 'ar' ? 'العقود النشطة' : 'active contracts',
            'overdue' => $locale === 'ar' ? 'المتأخرات' : 'overdue installments',
            'branch_performance' => $locale === 'ar' ? 'أداء الفروع' : 'branch performance',
            'agent_performance' => $locale === 'ar' ? 'أداء الموظفين' : 'agent performance',
            default => $type,
        };
    }

    private function salesReport(User $user, string $from, string $to, ?int $branchId, bool $detailed = false, int $limit = 10): array
    {
        $base = Order::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereIn('status', ['completed', 'converted_to_contract', 'approved'])
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId));

        $summary = (clone $base)->selectRaw('
            COUNT(*) as total_orders,
            COALESCE(SUM(total), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN type = "cash" THEN total ELSE 0 END), 0) as cash_revenue,
            COALESCE(SUM(CASE WHEN type = "installment" THEN total ELSE 0 END), 0) as installment_revenue,
            COALESCE(AVG(total), 0) as avg_order_value
        ')->first();

        $result = [
            'summary' => [
                'total_orders' => (int) ($summary?->total_orders ?? 0),
                'total_revenue' => (float) ($summary?->total_revenue ?? 0),
                'cash_revenue' => (float) ($summary?->cash_revenue ?? 0),
                'installment_revenue' => (float) ($summary?->installment_revenue ?? 0),
                'avg_order_value' => (float) ($summary?->avg_order_value ?? 0),
            ],
        ];

        $daily = (clone $base)
            ->selectRaw('DATE(created_at) as report_date, COUNT(*) as total_orders, COALESCE(SUM(total), 0) as total_revenue')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('report_date')
            ->get()
            ->map(fn ($item) => [
                'date' => $item->report_date,
                'total_orders' => (int) $item->total_orders,
                'total_revenue' => (float) $item->total_revenue,
            ])
            ->values()
            ->all();

        if ($daily !== []) {
            $result['daily_breakdown'] = $daily;
        }

        if ($detailed) {
            $items = (clone $base)
                ->with(['customer', 'branch', 'salesAgent'])
                ->latest()
                ->limit($limit)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'date' => optional($order->created_at)?->toDateString(),
                    'type' => $order->type,
                    'status' => $order->status,
                    'total' => (float) $order->total,
                    'customer' => $order->customer?->name,
                    'branch' => $order->branch?->name,
                    'sales_agent' => $order->salesAgent?->name,
                ])
                ->values()
                ->all();

            $result['items'] = $items;
            $result['count'] = count($items);
        }

        return $result;
    }

    private function collectionsReport(User $user, string $from, string $to, ?int $branchId, bool $detailed = false, int $limit = 10): array
    {
        $base = Payment::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereBetween('payment_date', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId));

        $summary = (clone $base)->selectRaw('
            COUNT(*) as total_payments,
            COALESCE(SUM(amount), 0) as total_collected,
            COALESCE(AVG(amount), 0) as avg_payment_value
        ')->first();

        $result = [
            'summary' => [
                'total_payments' => (int) ($summary?->total_payments ?? 0),
                'total_collected' => (float) ($summary?->total_collected ?? 0),
                'avg_payment_value' => (float) ($summary?->avg_payment_value ?? 0),
            ],
        ];

        $daily = (clone $base)
            ->selectRaw('payment_date as report_date, COUNT(*) as total_payments, COALESCE(SUM(amount), 0) as total_collected')
            ->groupBy('payment_date')
            ->orderBy('report_date')
            ->get()
            ->map(fn ($item) => [
                'date' => $item->report_date,
                'total_payments' => (int) $item->total_payments,
                'total_collected' => (float) $item->total_collected,
            ])
            ->values()
            ->all();

        if ($daily !== []) {
            $result['daily_breakdown'] = $daily;
        }

        if ($detailed) {
            $items = (clone $base)
                ->with(['customer', 'contract', 'branch'])
                ->latest('payment_date')
                ->limit($limit)
                ->get()
                ->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'receipt_number' => $payment->receipt_number,
                    'payment_date' => $payment->payment_date,
                    'amount' => (float) $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'customer' => $payment->customer?->name,
                    'contract' => $payment->contract?->contract_number,
                    'branch' => $payment->branch?->name,
                ])
                ->values()
                ->all();

            $result['items'] = $items;
            $result['count'] = count($items);
        }

        return $result;
    }

    private function activeContractsReport(User $user, ?int $branchId): array
    {
        $base = InstallmentContract::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'active')
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId));

        $summary = (clone $base)->selectRaw('
            COUNT(*) as total,
            COALESCE(SUM(remaining_amount), 0) as total_remaining,
            COALESCE(SUM(paid_amount), 0) as total_paid,
            COALESCE(SUM(total_amount), 0) as portfolio_value,
            COALESCE(AVG(monthly_amount), 0) as avg_monthly_amount
        ')->first();

        return [
            'summary' => [
                'total' => (int) ($summary?->total ?? 0),
                'total_remaining' => (float) ($summary?->total_remaining ?? 0),
                'total_paid' => (float) ($summary?->total_paid ?? 0),
                'portfolio_value' => (float) ($summary?->portfolio_value ?? 0),
                'avg_monthly_amount' => (float) ($summary?->avg_monthly_amount ?? 0),
            ],
        ];
    }

    private function overdueReport(User $user, ?int $branchId): array
    {
        $base = InstallmentSchedule::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'overdue')
            ->when($branchId !== null, fn ($query) => $query->whereHas('contract', fn ($contractQuery) => $contractQuery->where('branch_id', $branchId)));

        $dates = (clone $base)->pluck('due_date')->filter();
        $avgDays = $dates->isEmpty()
            ? 0
            : round($dates->map(fn ($date) => Carbon::parse($date)->startOfDay()->diffInDays(now()->startOfDay()))->avg(), 2);

        return [
            'summary' => [
                'total' => (clone $base)->count(),
                'total_overdue' => (float) (clone $base)->sum('remaining_amount'),
                'unique_contracts' => (int) (clone $base)->distinct('contract_id')->count('contract_id'),
                'avg_days_overdue' => (float) $avgDays,
            ],
        ];
    }

    private function branchPerformanceReport(User $user, string $from, string $to): array
    {
        $query = Branch::query()
            ->where('tenant_id', $user->tenant_id)
            ->when(TenantBranchScope::isBranchScoped($user), fn ($builder) => $builder->whereKey($user->branch_id))
            ->withCount('users')
            ->get()
            ->map(function (Branch $branch) use ($user, $from, $to) {
                $orders = Order::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('branch_id', $branch->id)
                    ->whereIn('status', ['completed', 'converted_to_contract', 'approved'])
                    ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);

                $payments = Payment::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('branch_id', $branch->id)
                    ->whereBetween('payment_date', [$from, $to]);

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'users_count' => (int) $branch->users_count,
                    'total_orders' => (int) (clone $orders)->count(),
                    'total_sales' => (float) (clone $orders)->sum('total'),
                    'avg_order_value' => round((float) ((clone $orders)->avg('total') ?? 0), 2),
                    'total_collections' => (float) (clone $payments)->sum('amount'),
                ];
            })
            ->values();

        return [
            'summary' => [
                'branches_count' => $query->count(),
                'top_branch' => $query->sortByDesc('total_sales')->first(),
            ],
            'items' => $query->all(),
        ];
    }

    private function agentPerformanceReport(User $user, string $from, string $to): array
    {
        $agents = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->when(TenantBranchScope::isBranchScoped($user), fn ($builder) => $builder->where('branch_id', $user->branch_id))
            ->get()
            ->map(function (User $agent) use ($user, $from, $to) {
                $orders = Order::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('sales_agent_id', $agent->id)
                    ->whereIn('status', ['completed', 'converted_to_contract', 'approved'])
                    ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'email' => $agent->email,
                    'total_orders' => (int) (clone $orders)->count(),
                    'total_sales' => (float) (clone $orders)->sum('total'),
                    'avg_order_value' => round((float) ((clone $orders)->avg('total') ?? 0), 2),
                    'cash_sales' => (float) (clone $orders)->where('type', 'cash')->sum('total'),
                    'installment_sales' => (float) (clone $orders)->where('type', 'installment')->sum('total'),
                ];
            })
            ->filter(fn (array $item) => $item['total_orders'] > 0)
            ->values();

        return [
            'summary' => [
                'agents_count' => $agents->count(),
                'top_agent' => $agents->sortByDesc('total_sales')->first(),
            ],
            'items' => $agents->all(),
        ];
    }

    private function clarification(User $user, string $ar, string $en, array $data = []): array
    {
        return [
            'status' => 'needs_clarification',
            'summary' => $this->loc($user, $ar, $en),
            'data' => $data,
        ];
    }

    private function rejected(User $user, string $ar, string $en, array $data = []): array
    {
        return [
            'status' => 'rejected',
            'summary' => $this->loc($user, $ar, $en),
            'data' => $data,
        ];
    }

    private function loc(User $user, string $ar, string $en): string
    {
        return ($user->locale ?? 'ar') === 'en' ? $en : $ar;
    }

    private function recordAudit(User $user, string $action, mixed $model, array $payload, string $description): void
    {
        AuditLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'action' => $action,
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model?->id,
            'old_values' => null,
            'new_values' => $payload,
            'description' => $description,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
