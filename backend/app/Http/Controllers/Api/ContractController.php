<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\StoreContractRequest;
use App\Http\Resources\ContractResource;
use App\Models\InstallmentContract;
use App\Models\Order;
use App\Services\ContractService;
use App\Support\TenantBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function __construct(private readonly ContractService $contractService) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $query = InstallmentContract::forTenant($tenantId)
            ->with(['customer', 'branch', 'order'])
            ->when($request->search, fn ($q) => $q->whereHas('customer', fn ($cq) => $cq->search($request->search)))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch))
            ->latest();

        $contracts = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => ContractResource::collection($contracts->items()),
            'meta' => [
                'total' => $contracts->total(),
                'per_page' => $contracts->perPage(),
                'current_page' => $contracts->currentPage(),
                'last_page' => $contracts->lastPage(),
            ],
        ]);
    }

    public function store(StoreContractRequest $request): JsonResponse
    {
        $order = Order::findOrFail($request->order_id);

        $this->authorizeTenant($request, $order->tenant_id);

        if (TenantBranchScope::isBranchScoped($request->user())
            && (int) $order->branch_id !== (int) $request->user()->branch_id) {
            abort(403, 'Access denied.');
        }

        $contract = $this->contractService->createFromOrder(
            $order,
            $request->validated(),
            $request->user()
        );

        return response()->json(['data' => new ContractResource($contract)], 201);
    }

    public function show(Request $request, InstallmentContract $contract): JsonResponse
    {
        $this->authorizeTenant($request, $contract->tenant_id);
        $this->authorizeBranchContract($request, $contract);

        $contract->load(['customer', 'order.items.product', 'branch', 'schedules', 'payments.collectedBy', 'createdBy']);

        return response()->json(['data' => new ContractResource($contract)]);
    }

    public function schedules(Request $request, InstallmentContract $contract): JsonResponse
    {
        $this->authorizeTenant($request, $contract->tenant_id);
        $this->authorizeBranchContract($request, $contract);

        $this->contractService->refreshStatus($contract);

        return response()->json(['data' => $contract->schedules]);
    }

    private function authorizeTenant(Request $request, int $tenantId): void
    {
        if (! $request->user()->isSuperAdmin() && $tenantId !== $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }
    }

    private function authorizeBranchContract(Request $request, InstallmentContract $contract): void
    {
        if (! TenantBranchScope::isBranchScoped($request->user())) {
            return;
        }

        if ((int) $contract->branch_id !== (int) $request->user()->branch_id) {
            abort(403, 'Access denied.');
        }
    }
}
