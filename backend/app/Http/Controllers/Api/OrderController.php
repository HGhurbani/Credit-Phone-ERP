<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Support\TenantBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly InvoiceService $invoiceService,
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

        $query = Order::forTenant($tenantId)
            ->with(['customer', 'branch', 'salesAgent'])
            ->when($request->search, fn ($q) => $q->whereHas('customer', fn ($cq) => $cq->search($request->search)))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch))
            ->latest();

        $orders = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => OrderResource::collection($orders->items()),
            'meta' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $user = $request->user();

        $order = $this->orderService->create(
            $request->validated(),
            $user->tenant_id,
            $request->branch_id ?? $user->branch_id,
            $user->id,
        );

        return response()->json(['data' => new OrderResource($order)], 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeTenant($request, $order);

        $order->load(['customer', 'branch', 'salesAgent', 'items.product', 'contract', 'invoice', 'approvedBy']);

        return response()->json(['data' => new OrderResource($order)]);
    }

    public function approve(Request $request, Order $order): JsonResponse
    {
        $this->authorizeTenant($request, $order);

        if (!in_array($order->status, ['draft', 'pending_review'])) {
            return response()->json(['message' => 'Order cannot be approved in its current status.'], 422);
        }

        $order = $this->orderService->approve($order, $request->user()->id);

        $orderForApi = $order->fresh()->load([
            'customer', 'branch', 'salesAgent', 'items.product', 'contract', 'invoice', 'approvedBy',
        ]);

        if ($orderForApi->isCash()) {
            $invoice = $this->invoiceService->createFromOrder($orderForApi, $request->user()->id);
            return response()->json(['data' => new OrderResource($orderForApi), 'invoice' => $invoice]);
        }

        return response()->json(['data' => new OrderResource($orderForApi)]);
    }

    public function reject(Request $request, Order $order): JsonResponse
    {
        $this->authorizeTenant($request, $order);

        $request->validate(['reason' => 'required|string|max:1000']);

        $order = $this->orderService->reject($order, $request->reason);

        return response()->json(['data' => new OrderResource($order)]);
    }

    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->authorizeTenant($request, $order);

        if (!in_array($order->status, ['draft', 'cancelled'])) {
            return response()->json(['message' => 'Only draft orders can be deleted.'], 422);
        }

        $order->delete();

        return response()->json(['message' => 'Order deleted.']);
    }

    private function authorizeTenant(Request $request, Order $order): void
    {
        if (! $request->user()->isSuperAdmin() && $order->tenant_id !== $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }

        if (TenantBranchScope::isBranchScoped($request->user())
            && (int) $order->branch_id !== (int) $request->user()->branch_id) {
            abort(403, 'Access denied.');
        }
    }
}
