<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrder\ReceiveGoodsRequest;
use App\Http\Requests\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\UpdatePurchaseOrderStatusRequest;
use App\Http\Resources\GoodsReceiptResource;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use App\Support\TenantBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly GoodsReceiptService $goodsReceiptService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $query = PurchaseOrder::forTenant($tenantId)
            ->with(['supplier', 'branch', 'createdBy'])
            ->when($request->search, fn ($q) => $q->where('purchase_number', 'like', '%'.$request->search.'%'))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch))
            ->latest();

        $paginator = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => PurchaseOrderResource::collection($paginator->items()),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $branchId = $request->input('branch_id') ?? $user->branch_id;
        if (! $branchId) {
            throw ValidationException::withMessages(['branch_id' => ['Branch is required.']]);
        }

        TenantBranchScope::assertBranchBelongsToTenant((int) $branchId, $tenantId);
        if (TenantBranchScope::isBranchScoped($user) && (int) $user->branch_id !== (int) $branchId) {
            abort(403, 'Access denied.');
        }

        $data = $request->validated();
        $data['branch_id'] = (int) $branchId;

        $po = $this->purchaseOrderService->create($data, $tenantId, $user->id);

        return response()->json(['data' => new PurchaseOrderResource($po)], 201);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeTenant($request, $purchaseOrder);

        $purchaseOrder->load([
            'supplier',
            'branch',
            'createdBy',
            'items.product',
            'goodsReceipts.items.purchaseOrderItem.product',
            'goodsReceipts.receivedBy',
            'goodsReceipts.branch',
        ]);

        return response()->json(['data' => new PurchaseOrderResource($purchaseOrder)]);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeTenant($request, $purchaseOrder);

        $user = $request->user();
        $tenantId = $user->tenant_id;

        if ($request->filled('branch_id')) {
            TenantBranchScope::assertBranchBelongsToTenant((int) $request->branch_id, $tenantId);
            if (TenantBranchScope::isBranchScoped($user) && (int) $user->branch_id !== (int) $request->branch_id) {
                abort(403, 'Access denied.');
            }
        }

        $po = $this->purchaseOrderService->update($purchaseOrder, $request->validated(), $tenantId);

        return response()->json(['data' => new PurchaseOrderResource($po)]);
    }

    public function updateStatus(UpdatePurchaseOrderStatusRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeTenant($request, $purchaseOrder);

        $po = $this->purchaseOrderService->updateStatus(
            $purchaseOrder,
            $request->validated()['status'],
            $request->user()->tenant_id
        );

        $po->load(['supplier', 'branch', 'createdBy', 'items.product']);

        return response()->json(['data' => new PurchaseOrderResource($po)]);
    }

    public function receive(ReceiveGoodsRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeTenant($request, $purchaseOrder);

        $receipt = $this->goodsReceiptService->receive(
            $purchaseOrder,
            $request->validated()['items'],
            $request->user(),
            $request->validated()['notes'] ?? null
        );

        $purchaseOrder->refresh()->load([
            'supplier',
            'branch',
            'createdBy',
            'items.product',
            'goodsReceipts.items.purchaseOrderItem.product',
            'goodsReceipts.receivedBy',
            'goodsReceipts.branch',
        ]);

        return response()->json([
            'data' => [
                'receipt' => new GoodsReceiptResource($receipt),
                'purchase_order' => new PurchaseOrderResource($purchaseOrder),
            ],
        ], 201);
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeTenant($request, $purchaseOrder);

        $this->purchaseOrderService->deleteIfAllowed($purchaseOrder);

        return response()->json(['message' => 'Purchase order deleted.']);
    }

    private function authorizeTenant(Request $request, PurchaseOrder $purchaseOrder): void
    {
        if (! $request->user()->isSuperAdmin() && $purchaseOrder->tenant_id !== $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }

        if (TenantBranchScope::isBranchScoped($request->user())
            && (int) $purchaseOrder->branch_id !== (int) $request->user()->branch_id) {
            abort(403, 'Access denied.');
        }
    }
}
