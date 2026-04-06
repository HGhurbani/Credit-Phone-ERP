<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\StoreContractRequest;
use App\Http\Resources\ContractResource;
use App\Models\InstallmentContract;
use App\Models\Order;
use App\Services\ContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function __construct(private readonly ContractService $contractService) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = InstallmentContract::forTenant($user->tenant_id)
            ->with(['customer', 'branch', 'order'])
            ->when($request->search, fn($q) => $q->whereHas('customer', fn($cq) => $cq->search($request->search)))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($user->branch_id && !$user->isSuperAdmin() && !$user->isCompanyAdmin(), fn($q) => $q->forBranch($user->branch_id))
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

        if (!$order->canBeConverted()) {
            return response()->json(['message' => 'Order is not eligible for contract creation.'], 422);
        }

        try {
            $contract = $this->contractService->createFromOrder(
                $order,
                $request->validated(),
                $request->user()->id
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new ContractResource($contract)], 201);
    }

    public function show(Request $request, InstallmentContract $contract): JsonResponse
    {
        $this->authorizeTenant($request, $contract->tenant_id);

        $contract->load(['customer', 'order.items.product', 'branch', 'schedules', 'payments.collectedBy', 'createdBy']);

        return response()->json(['data' => new ContractResource($contract)]);
    }

    public function schedules(Request $request, InstallmentContract $contract): JsonResponse
    {
        $this->authorizeTenant($request, $contract->tenant_id);

        $this->contractService->refreshStatus($contract);

        return response()->json(['data' => $contract->schedules]);
    }

    private function authorizeTenant(Request $request, int $tenantId): void
    {
        if (!$request->user()->isSuperAdmin() && $tenantId !== $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }
    }
}
