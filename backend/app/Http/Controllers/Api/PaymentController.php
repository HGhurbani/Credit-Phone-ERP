<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\TenantBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $query = Payment::forTenant($tenantId)
            ->with(['customer', 'contract', 'collectedBy', 'branch'])
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch))
            ->when($request->customer_id, fn ($q) => $q->where('customer_id', $request->customer_id))
            ->when($request->contract_id, fn ($q) => $q->where('contract_id', $request->contract_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('payment_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('payment_date', '<=', $request->date_to))
            ->latest();

        $payments = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => PaymentResource::collection($payments->items()),
            'meta' => [
                'total' => $payments->total(),
                'per_page' => $payments->perPage(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $contract = InstallmentContract::findOrFail($request->contract_id);

        if ($contract->tenant_id !== $request->user()->tenant_id) {
            abort(403);
        }

        if (TenantBranchScope::isBranchScoped($request->user())
            && (int) $contract->branch_id !== (int) $request->user()->branch_id) {
            abort(403, 'Access denied.');
        }

        $payment = $this->paymentService->record($contract, $request->validated(), $request->user()->id);

        return response()->json(['data' => new PaymentResource($payment)], 201);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->tenant_id !== $request->user()->tenant_id) {
            abort(403);
        }

        if (TenantBranchScope::isBranchScoped($request->user())
            && (int) $payment->branch_id !== (int) $request->user()->branch_id) {
            abort(403, 'Access denied.');
        }

        $payment->load(['customer', 'contract', 'schedule', 'collectedBy', 'receipt']);

        return response()->json(['data' => new PaymentResource($payment)]);
    }

    public function dueToday(Request $request): JsonResponse
    {
        $user = $request->user();

        $schedules = InstallmentSchedule::where('tenant_id', $user->tenant_id)
            ->whereDate('due_date', today())
            ->whereIn('status', ['upcoming', 'due_today', 'partial'])
            ->with(['contract.customer', 'contract.branch'])
            ->when(TenantBranchScope::isBranchScoped($user), fn ($q) => $q->whereHas('contract', fn ($cq) => $cq->where('branch_id', $user->branch_id)))
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'data' => $schedules->items(),
            'meta' => [
                'total' => $schedules->total(),
                'current_page' => $schedules->currentPage(),
                'last_page' => $schedules->lastPage(),
            ],
        ]);
    }

    public function overdue(Request $request): JsonResponse
    {
        $user = $request->user();

        $schedules = InstallmentSchedule::where('tenant_id', $user->tenant_id)
            ->where('status', 'overdue')
            ->with(['contract.customer', 'contract.branch'])
            ->when(TenantBranchScope::isBranchScoped($user), fn ($q) => $q->whereHas('contract', fn ($cq) => $cq->where('branch_id', $user->branch_id)))
            ->orderBy('due_date')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'data' => $schedules->items(),
            'meta' => [
                'total' => $schedules->total(),
                'current_page' => $schedules->currentPage(),
                'last_page' => $schedules->lastPage(),
            ],
        ]);
    }
}
