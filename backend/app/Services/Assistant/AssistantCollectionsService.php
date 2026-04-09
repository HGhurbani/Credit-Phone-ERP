<?php

namespace App\Services\Assistant;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CollectionFollowUp;
use App\Models\Customer;
use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\PromiseToPay;
use App\Models\RescheduleRequest;
use App\Models\User;
use App\Services\CustomerStatementService;
use App\Support\TenantBranchScope;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AssistantCollectionsService
{
    public function __construct(
        private readonly CustomerStatementService $customerStatementService,
    ) {}

    public function execute(User $user, string $operation, ?string $target, array $arguments, string $channel): array
    {
        return match ($operation) {
            'query' => $this->queryCollections($user, $target, $arguments),
            'create' => $this->createCollectionEntry($user, $target, $arguments, $channel),
            'run' => $this->runCollectionsCopilot($user, $target, $arguments, $channel),
            default => $this->rejected($user, 'عملية التحصيل غير مدعومة.', 'Collections operation is not supported.'),
        };
    }

    private function queryCollections(User $user, ?string $target, array $arguments): array
    {
        $type = $this->normalizeCollectionType($arguments['collection_type'] ?? $arguments['action'] ?? $target);
        if ($type === null) {
            return $this->clarification(
                $user,
                'حدد نوع طلب التحصيل: كشف حساب، مستحق اليوم، متأخرات، متابعات، وعود سداد، طلبات إعادة جدولة، أو مساعد التحصيل.',
                'Choose the collections request type: statement, due today, overdue, follow-ups, promises to pay, reschedule requests, or copilot.'
            );
        }

        return match ($type) {
            'statement' => $this->queryCustomerStatement($user, $target, $arguments),
            'due_today' => $this->querySchedules($user, $arguments, false),
            'overdue' => $this->querySchedules($user, $arguments, true),
            'follow_ups' => $this->listCustomerCollections($user, $target, $arguments, CollectionFollowUp::class, 'follow-ups'),
            'promises_to_pay' => $this->listCustomerCollections($user, $target, $arguments, PromiseToPay::class, 'promises'),
            'reschedule_requests' => $this->listCustomerCollections($user, $target, $arguments, RescheduleRequest::class, 'reschedules'),
            'copilot' => $this->runCollectionsCopilot($user, $target, $arguments, 'web'),
        };
    }

    private function createCollectionEntry(User $user, ?string $target, array $arguments, string $channel): array
    {
        $action = $this->normalizeCollectionAction($arguments['collection_action'] ?? $arguments['action'] ?? $arguments['collection_type'] ?? null);
        if ($action === null) {
            return $this->clarification(
                $user,
                'حدد إجراء التحصيل المطلوب: متابعة، وعد سداد، أو طلب إعادة جدولة.',
                'Choose the collections action: follow-up, promise to pay, or reschedule request.'
            );
        }

        $customerContext = $this->resolveCustomerContext($user, $target, $arguments);
        if ($customerContext['status'] !== 'resolved') {
            return $customerContext['response'];
        }

        /** @var Customer $customer */
        $customer = $customerContext['customer'];
        $contract = $this->resolveContractForCustomer($user, $customer, $arguments);
        if (is_array($contract)) {
            return $contract;
        }

        return match ($action) {
            'follow_up' => $this->createFollowUp($user, $customer, $contract, $arguments, $channel),
            'promise_to_pay' => $this->createPromise($user, $customer, $contract, $arguments, $channel),
            'reschedule_request' => $this->createReschedule($user, $customer, $contract, $arguments, $channel),
        };
    }

    private function runCollectionsCopilot(User $user, ?string $target, array $arguments, string $channel): array
    {
        $customerHint = $target ?? ($arguments['customer'] ?? $arguments['customer_id'] ?? null);
        if ($customerHint !== null && $customerHint !== '') {
            return $this->runCustomerCollectionsCopilot($user, $customerHint, $channel);
        }

        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);
        $limit = $this->limitFromArguments($arguments);

        $overdue = InstallmentSchedule::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'overdue')
            ->with(['contract.customer', 'contract.branch'])
            ->when($branchId !== null, fn ($q) => $q->whereHas('contract', fn ($contractQuery) => $contractQuery->where('branch_id', $branchId)))
            ->orderBy('due_date')
            ->limit(max($limit * 3, 15))
            ->get();

        $items = $overdue->map(function (InstallmentSchedule $schedule) {
            $daysOverdue = $schedule->due_date ? Carbon::parse($schedule->due_date)->startOfDay()->diffInDays(now()->startOfDay()) : 0;
            $remainingAmount = (float) $schedule->remaining_amount;
            $riskScore = round(($daysOverdue * 3) + min($remainingAmount / 100, 50), 2);

            return [
                'schedule_id' => $schedule->id,
                'contract_number' => $schedule->contract?->contract_number,
                'customer' => $schedule->contract?->customer?->name,
                'branch' => $schedule->contract?->branch?->name,
                'due_date' => $schedule->due_date?->toDateString(),
                'remaining_amount' => $remainingAmount,
                'days_overdue' => $daysOverdue,
                'risk_score' => $riskScore,
                'recommended_action' => $daysOverdue >= 30 ? 'urgent_follow_up' : ($daysOverdue >= 7 ? 'call_and_promise' : 'reminder'),
            ];
        })->sortByDesc('risk_score')->take($limit)->values()->all();

        $summary = [
            'overdue_installments' => $overdue->count(),
            'overdue_amount' => round((float) $overdue->sum('remaining_amount'), 2),
            'due_today_installments' => InstallmentSchedule::query()->where('tenant_id', $user->tenant_id)->whereDate('due_date', today())->whereIn('status', ['upcoming', 'due_today', 'partial'])->when($branchId !== null, fn ($q) => $q->whereHas('contract', fn ($contractQuery) => $contractQuery->where('branch_id', $branchId)))->count(),
            'promises_due_now' => PromiseToPay::query()->where('tenant_id', $user->tenant_id)->where('status', 'active')->whereDate('promised_date', '<=', today())->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->count(),
            'pending_reschedule_requests' => RescheduleRequest::query()->where('tenant_id', $user->tenant_id)->where('status', 'pending')->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->count(),
            'priority_items' => count($items),
        ];

        $response = [
            'status' => 'completed',
            'summary' => $this->loc($user, 'تم تجهيز مساعد التحصيل اليومي مع أولويات جاهزة.', 'Prepared the daily collections copilot with ranked priorities.'),
            'data' => [
                'summary' => $summary,
                'recommendations' => [
                    $this->loc($user, 'ابدأ بأعلى 5 حالات حسب درجة الخطورة ثم سجّل متابعة مباشرة بعد كل اتصال.', 'Start with the top 5 highest-risk cases and log a follow-up after each call.'),
                    $this->loc($user, 'راجع وعود السداد المستحقة اليوم قبل فتح حالات جديدة.', 'Review promises due today before opening new cases.'),
                ],
                'items' => $items,
                'count' => count($items),
            ],
        ];

        $this->recordAudit($user, 'assistant.collections.copilot', null, ['channel' => $channel, 'branch_id' => $branchId, 'summary' => $summary], $response['summary']);

        return $response;
    }
    private function runCustomerCollectionsCopilot(User $user, mixed $target, string $channel): array
    {
        $resolved = $this->resolveCustomerTarget($user, $target);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Customer $customer */
        $customer = $resolved['model'];
        $statement = $this->customerStatementService->build($customer);
        $overdueCount = count($statement['overdue_installments'] ?? []);
        $activePromises = count($statement['active_promises_to_pay'] ?? []);
        $pendingReschedules = count($statement['pending_reschedule_requests'] ?? []);
        $totalOutstanding = (float) ($statement['summary']['total_outstanding'] ?? 0);
        $priority = match (true) {
            $overdueCount >= 3 || $totalOutstanding >= 5000 => 'high',
            $overdueCount >= 1 || $totalOutstanding >= 1000 => 'normal',
            default => 'low',
        };

        $response = [
            'status' => 'completed',
            'summary' => $this->loc($user, "تم تجهيز مساعد التحصيل للعميل {$customer->name}.", "Prepared the collections copilot for {$customer->name}."),
            'data' => [
                'customer' => ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone, 'branch_id' => $customer->branch_id],
                'copilot_summary' => [
                    'priority' => $priority,
                    'total_outstanding' => $totalOutstanding,
                    'overdue_installments' => $overdueCount,
                    'active_promises' => $activePromises,
                    'pending_reschedules' => $pendingReschedules,
                ],
                'next_best_action' => $pendingReschedules > 0
                    ? $this->loc($user, 'راجع طلب إعادة الجدولة قبل الضغط على العميل بسداد جديد.', 'Review the pending reschedule request before pushing for a new payment.')
                    : ($activePromises > 0
                        ? $this->loc($user, 'تابع وعد السداد المفتوح أولاً ثم حدّث الحالة.', 'Follow up on the active promise to pay first, then update the status.')
                        : $this->loc($user, 'اتصل بالعميل الآن وسجّل متابعة تحصيل مع موعد متابعة قريب.', 'Call the customer now and log a follow-up with a near-term next date.')),
                'statement_snapshot' => [
                    'summary' => $statement['summary'] ?? [],
                    'active_contracts' => array_slice($statement['active_contracts'] ?? [], 0, 3),
                    'overdue_installments' => array_slice($statement['overdue_installments'] ?? [], 0, 5),
                    'active_promises_to_pay' => array_slice($statement['active_promises_to_pay'] ?? [], 0, 5),
                    'pending_reschedule_requests' => array_slice($statement['pending_reschedule_requests'] ?? [], 0, 5),
                ],
            ],
        ];

        $this->recordAudit($user, 'assistant.collections.customer_copilot', $customer, ['channel' => $channel, 'priority' => $priority], $response['summary']);

        return $response;
    }

    private function queryCustomerStatement(User $user, ?string $target, array $arguments): array
    {
        $resolved = $this->resolveCustomerTarget($user, $target ?? ($arguments['customer_id'] ?? $arguments['customer'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Customer $customer */
        $customer = $resolved['model'];

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, "تم تجهيز كشف حساب العميل {$customer->name}.", "Prepared the customer statement for {$customer->name}."),
            'data' => $this->customerStatementService->build($customer),
        ];
    }

    private function querySchedules(User $user, array $arguments, bool $overdue): array
    {
        $branchId = $this->resolveBranchIdForUser($user, isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null);
        $items = InstallmentSchedule::query()
            ->where('tenant_id', $user->tenant_id)
            ->when($overdue, fn ($q) => $q->where('status', 'overdue'), fn ($q) => $q->whereDate('due_date', today())->whereIn('status', ['upcoming', 'due_today', 'partial']))
            ->with(['contract.customer', 'contract.branch'])
            ->when($branchId !== null, fn ($q) => $q->whereHas('contract', fn ($contractQuery) => $contractQuery->where('branch_id', $branchId)))
            ->orderBy('due_date')
            ->limit($this->limitFromArguments($arguments))
            ->get()
            ->map(function (InstallmentSchedule $schedule) use ($overdue) {
                return [
                    'schedule_id' => $schedule->id,
                    'contract_id' => $schedule->contract_id,
                    'contract_number' => $schedule->contract?->contract_number,
                    'customer' => $schedule->contract?->customer?->name,
                    'branch' => $schedule->contract?->branch?->name,
                    'due_date' => $schedule->due_date?->toDateString(),
                    'amount' => (float) $schedule->amount,
                    'remaining_amount' => (float) $schedule->remaining_amount,
                    'status' => $schedule->status,
                    'days_overdue' => $overdue && $schedule->due_date ? Carbon::parse($schedule->due_date)->startOfDay()->diffInDays(now()->startOfDay()) : 0,
                ];
            })
            ->values()
            ->all();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, $overdue ? 'تم العثور على '.count($items).' قسط متأخر.' : 'تم العثور على '.count($items).' قسط مستحق اليوم.', $overdue ? 'Found '.count($items).' overdue installments.' : 'Found '.count($items).' installments due today.'),
            'data' => ['items' => $items, 'count' => count($items)],
        ];
    }

    private function listCustomerCollections(User $user, ?string $target, array $arguments, string $modelClass, string $kind): array
    {
        $resolved = $this->resolveCustomerTarget($user, $target ?? ($arguments['customer'] ?? $arguments['customer_id'] ?? null));
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var Customer $customer */
        $customer = $resolved['model'];
        $items = $modelClass::query()
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->when(isset($arguments['contract_id']), fn ($q) => $q->where('contract_id', (int) $arguments['contract_id']))
            ->with(['createdBy:id,name', 'contract:id,contract_number'])
            ->latest()
            ->limit($this->limitFromArguments($arguments))
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'status' => $row->status ?? null,
                'outcome' => $row->outcome ?? null,
                'priority' => $row->priority ?? null,
                'promised_amount' => isset($row->promised_amount) ? (float) $row->promised_amount : null,
                'promised_date' => $row->promised_date?->toDateString(),
                'next_follow_up_date' => $row->next_follow_up_date?->toDateString(),
                'contract_id' => $row->contract_id,
                'contract_number' => $row->contract?->contract_number,
                'note' => $row->note,
                'created_by' => $row->createdBy?->name,
                'created_at' => $row->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();

        return [
            'status' => 'completed',
            'summary' => $this->loc($user, "تم العثور على ".count($items)." من سجلات {$kind} للعميل {$customer->name}.", "Found ".count($items)." {$kind} records for {$customer->name}."),
            'data' => ['customer' => ['id' => $customer->id, 'name' => $customer->name], 'items' => $items, 'count' => count($items)],
        ];
    }

    private function createFollowUp(User $user, Customer $customer, ?InstallmentContract $contract, array $arguments, string $channel): array
    {
        $validator = Validator::make([
            'outcome' => $arguments['outcome'] ?? null,
            'next_follow_up_date' => $arguments['next_follow_up_date'] ?? null,
            'priority' => $arguments['priority'] ?? 'normal',
            'note' => $arguments['note'] ?? null,
        ], [
            'outcome' => ['required', Rule::in(['contacted', 'no_answer', 'promise_to_pay', 'wrong_number', 'reschedule_requested', 'visited'])],
            'next_follow_up_date' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات متابعة التحصيل غير صحيحة: '.$validator->errors()->first(), 'Collection follow-up data is invalid: '.$validator->errors()->first());
        }

        $followUp = CollectionFollowUp::create([
            'tenant_id' => $customer->tenant_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'contract_id' => $contract?->id,
            'outcome' => $validator->validated()['outcome'],
            'next_follow_up_date' => $validator->validated()['next_follow_up_date'] ?? null,
            'priority' => $validator->validated()['priority'] ?? 'normal',
            'note' => $validator->validated()['note'] ?? null,
            'created_by' => $user->id,
        ]);

        $summary = $this->loc($user, "تم تسجيل متابعة تحصيل للعميل {$customer->name}.", "Created a collection follow-up for {$customer->name}.");
        $this->recordAudit($user, 'assistant.collections.follow_up.create', $followUp, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['id' => $followUp->id, 'customer' => $customer->name, 'contract_id' => $contract?->id, 'outcome' => $followUp->outcome, 'priority' => $followUp->priority, 'next_follow_up_date' => $followUp->next_follow_up_date?->toDateString(), 'note' => $followUp->note]];
    }

    private function createPromise(User $user, Customer $customer, ?InstallmentContract $contract, array $arguments, string $channel): array
    {
        $validator = Validator::make([
            'promised_amount' => $arguments['promised_amount'] ?? $arguments['amount'] ?? null,
            'promised_date' => $arguments['promised_date'] ?? null,
            'note' => $arguments['note'] ?? null,
        ], [
            'promised_amount' => ['required', 'numeric', 'min:0.01'],
            'promised_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);

        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات وعد السداد غير صحيحة: '.$validator->errors()->first(), 'Promise-to-pay data is invalid: '.$validator->errors()->first());
        }

        $promise = PromiseToPay::create([
            'tenant_id' => $customer->tenant_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'contract_id' => $contract?->id,
            'promised_amount' => $validator->validated()['promised_amount'],
            'promised_date' => $validator->validated()['promised_date'],
            'note' => $validator->validated()['note'] ?? null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $summary = $this->loc($user, "تم تسجيل وعد سداد للعميل {$customer->name}.", "Created a promise to pay for {$customer->name}.");
        $this->recordAudit($user, 'assistant.collections.promise.create', $promise, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['id' => $promise->id, 'customer' => $customer->name, 'contract_id' => $contract?->id, 'promised_amount' => (float) $promise->promised_amount, 'promised_date' => $promise->promised_date?->toDateString(), 'status' => $promise->status, 'note' => $promise->note]];
    }

    private function createReschedule(User $user, Customer $customer, ?InstallmentContract $contract, array $arguments, string $channel): array
    {
        if (! $contract) {
            return $this->clarification($user, 'طلب إعادة الجدولة يحتاج تحديد العقد.', 'A reschedule request requires a contract.');
        }

        $validator = Validator::make(['note' => $arguments['note'] ?? null], ['note' => ['nullable', 'string', 'max:10000']]);
        if ($validator->fails()) {
            return $this->clarification($user, 'بيانات طلب إعادة الجدولة غير صحيحة: '.$validator->errors()->first(), 'Reschedule request data is invalid: '.$validator->errors()->first());
        }

        $request = RescheduleRequest::create([
            'tenant_id' => $customer->tenant_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'note' => $validator->validated()['note'] ?? null,
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        $summary = $this->loc($user, "تم إنشاء طلب إعادة جدولة للعميل {$customer->name}.", "Created a reschedule request for {$customer->name}.");
        $this->recordAudit($user, 'assistant.collections.reschedule.create', $request, ['channel' => $channel], $summary);

        return ['status' => 'completed', 'summary' => $summary, 'data' => ['id' => $request->id, 'customer' => $customer->name, 'contract_id' => $contract->id, 'status' => $request->status, 'note' => $request->note]];
    }

    private function normalizeCollectionType(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = str_replace(['-', ' '], '_', mb_strtolower(trim($value), 'UTF-8'));

        return match (true) {
            str_contains($normalized, 'statement') || str_contains($normalized, 'كشف') => 'statement',
            str_contains($normalized, 'due_today') || str_contains($normalized, 'مستحق') => 'due_today',
            str_contains($normalized, 'overdue') || str_contains($normalized, 'متأخر') => 'overdue',
            str_contains($normalized, 'follow') || str_contains($normalized, 'متابع') => 'follow_ups',
            str_contains($normalized, 'promise') || str_contains($normalized, 'وعد') => 'promises_to_pay',
            str_contains($normalized, 'reschedule') || str_contains($normalized, 'جدول') => 'reschedule_requests',
            str_contains($normalized, 'copilot') || str_contains($normalized, 'مساعد') || str_contains($normalized, 'priority') => 'copilot',
            default => null,
        };
    }

    private function normalizeCollectionAction(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = str_replace(['-', ' '], '_', mb_strtolower(trim($value), 'UTF-8'));

        return match (true) {
            str_contains($normalized, 'follow') || str_contains($normalized, 'متابع') => 'follow_up',
            str_contains($normalized, 'promise') || str_contains($normalized, 'وعد') => 'promise_to_pay',
            str_contains($normalized, 'reschedule') || str_contains($normalized, 'جدول') => 'reschedule_request',
            default => null,
        };
    }

    private function resolveCustomerContext(User $user, ?string $target, array $arguments): array
    {
        $customerTarget = $target ?? ($arguments['customer'] ?? $arguments['customer_id'] ?? null);
        if ($customerTarget !== null && $customerTarget !== '') {
            $resolved = $this->resolveCustomerTarget($user, $customerTarget);

            return $resolved['status'] === 'resolved'
                ? ['status' => 'resolved', 'customer' => $resolved['model']]
                : ['status' => $resolved['status'], 'response' => $resolved['response']];
        }

        $contractTarget = $arguments['contract_id'] ?? $arguments['contract'] ?? null;
        if ($contractTarget !== null && $contractTarget !== '') {
            $resolved = $this->resolveContractTarget($user, $contractTarget);

            return $resolved['status'] === 'resolved'
                ? ['status' => 'resolved', 'customer' => $resolved['model']->customer]
                : ['status' => $resolved['status'], 'response' => $resolved['response']];
        }

        return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد العميل المقصود بالاسم أو الرقم.', 'Specify the customer by name or ID.')];
    }

    private function resolveContractForCustomer(User $user, Customer $customer, array $arguments): InstallmentContract|array|null
    {
        $contractTarget = $arguments['contract_id'] ?? $arguments['contract'] ?? null;
        if ($contractTarget === null || $contractTarget === '') {
            return null;
        }

        $resolved = $this->resolveContractTarget($user, $contractTarget);
        if ($resolved['status'] !== 'resolved') {
            return $resolved['response'];
        }

        /** @var InstallmentContract $contract */
        $contract = $resolved['model'];

        return (int) $contract->customer_id === (int) $customer->id
            ? $contract
            : $this->rejected($user, 'العقد المحدد لا يخص هذا العميل.', 'The selected contract does not belong to this customer.');
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

        $matches = (clone $query)->where(fn ($q) => $q->where('name', 'like', '%'.$target.'%')->orWhere('phone', 'like', '%'.$target.'%')->orWhere('email', 'like', '%'.$target.'%')->orWhere('national_id', 'like', '%'.$target.'%'))->limit(3)->get();

        return $this->resolveMatchSet($user, $matches, 'عميل', 'customer');
    }

    private function resolveContractTarget(User $user, mixed $target): array
    {
        if ($target === null || $target === '') {
            return ['status' => 'needs_clarification', 'response' => $this->clarification($user, 'حدد العقد المقصود برقم العقد أو المعرّف.', 'Specify the contract by contract number or ID.')];
        }

        $query = InstallmentContract::query()->where('tenant_id', $user->tenant_id)->with('customer');
        if (TenantBranchScope::isBranchScoped($user)) {
            $query->where('branch_id', $user->branch_id);
        }

        if (is_numeric($target)) {
            $model = (clone $query)->find((int) $target);
            if ($model) {
                return ['status' => 'resolved', 'model' => $model];
            }
        }

        $matches = (clone $query)->where(fn ($q) => $q->where('contract_number', 'like', '%'.$target.'%')->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', '%'.$target.'%')))->limit(3)->get();

        return $this->resolveMatchSet($user, $matches, 'عقد', 'contract');
    }

    private function resolveBranchIdForUser(User $user, ?int $requestedBranchId = null): ?int
    {
        if (TenantBranchScope::isBranchScoped($user)) {
            return $user->branch_id ? (int) $user->branch_id : null;
        }

        if ($requestedBranchId === null) {
            return null;
        }

        return Branch::query()->where('tenant_id', $user->tenant_id)->whereKey($requestedBranchId)->exists() ? $requestedBranchId : null;
    }

    private function resolveMatchSet(User $user, Collection $matches, string $labelAr, string $labelEn): array
    {
        if ($matches->count() === 1) {
            return ['status' => 'resolved', 'model' => $matches->first()];
        }

        if ($matches->isEmpty()) {
            return ['status' => 'not_found', 'response' => $this->clarification($user, "لم أجد {$labelAr} مطابقاً. جرّب الاسم الكامل أو المعرّف.", "I could not find a matching {$labelEn}. Try the full name or ID.")];
        }

        $options = $matches->values()->map(function ($item, int $index) {
            return ['number' => $index + 1, 'value' => (string) $item->id, 'label' => $this->modelChoiceLabel($item)];
        })->all();
        $optionsAr = collect($options)->map(fn (array $option) => $option['number'].') '.$option['label'])->implode('، ');
        $optionsEn = collect($options)->map(fn (array $option) => $option['number'].') '.$option['label'])->implode(', ');

        return [
            'status' => 'ambiguous',
            'response' => $this->clarification(
                $user,
                "وجدت أكثر من {$labelAr}. اختر رقم الخيار المناسب: {$optionsAr}",
                "I found more than one matching {$labelEn}. Choose the option number: {$optionsEn}",
                ['clarification' => ['kind' => 'selection', 'field' => 'target', 'allow_none' => false, 'options' => $options]]
            ),
        ];
    }

    private function modelChoiceLabel(mixed $model): string
    {
        $parts = array_filter([$model->name ?? $model->contract_number ?? null, isset($model->id) ? '#'.$model->id : null]);

        return implode(' ', $parts);
    }

    private function limitFromArguments(array $arguments): int
    {
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 10;

        return max(1, min($limit, 50));
    }

    private function clarification(User $user, string $ar, string $en, array $data = []): array
    {
        return ['status' => 'needs_clarification', 'summary' => $this->loc($user, $ar, $en), 'data' => $data];
    }

    private function rejected(User $user, string $ar, string $en, array $data = []): array
    {
        return ['status' => 'rejected', 'summary' => $this->loc($user, $ar, $en), 'data' => $data];
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
