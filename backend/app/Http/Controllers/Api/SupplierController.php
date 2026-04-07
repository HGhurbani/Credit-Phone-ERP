<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $query = Supplier::forTenant($tenantId)
            ->when($request->search, fn ($q) => $q->where(function ($q2) use ($request) {
                $s = $request->search;
                $q2->where('name', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            }))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest();

        $suppliers = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => SupplierResource::collection($suppliers->items()),
            'meta' => [
                'total' => $suppliers->total(),
                'per_page' => $suppliers->perPage(),
                'current_page' => $suppliers->currentPage(),
                'last_page' => $suppliers->lastPage(),
            ],
        ]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
        ]);

        return response()->json(['data' => new SupplierResource($supplier)], 201);
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeTenant($request, $supplier);

        return response()->json(['data' => new SupplierResource($supplier)]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeTenant($request, $supplier);

        $supplier->update($request->validated());

        return response()->json(['data' => new SupplierResource($supplier->fresh())]);
    }

    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeTenant($request, $supplier);

        if ($supplier->purchaseOrders()->exists()) {
            throw ValidationException::withMessages([
                'supplier' => ['Cannot delete: this supplier is linked to purchase orders.'],
            ]);
        }

        $supplier->delete();

        return response()->json(['message' => 'Supplier deleted.']);
    }

    private function authorizeTenant(Request $request, Supplier $supplier): void
    {
        if ($request->user()->isSuperAdmin()) {
            return;
        }

        if ((int) $supplier->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }
    }
}
