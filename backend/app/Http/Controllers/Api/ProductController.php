<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\TenantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $query = Product::forTenant($tenantId)
            ->with(['category', 'brand'])
            ->when($request->search, fn($q) => $q->search($request->search))
            ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
            ->when($request->brand_id, fn($q) => $q->where('brand_id', $request->brand_id))
            ->when($request->boolean('active_only'), fn($q) => $q->active())
            ->latest();

        $products = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => ProductResource::collection($products->items()),
            'meta' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = DB::transaction(function () use ($request) {
            $data = $request->validated();
            if (TenantSettings::string($request->user()->tenant_id, 'installment_pricing_mode', 'percentage') === 'fixed') {
                $months = min(array_map('intval', $data['allowed_durations']));
                $data['installment_price'] = round((float) $data['min_down_payment'] + (float) $data['fixed_monthly_amount'] * $months, 2);
            }

            $product = Product::create([
                ...$data,
                'tenant_id' => $request->user()->tenant_id,
            ]);

            // Initialize inventory for all branches
            $branches = $request->user()->tenant->branches;
            foreach ($branches as $branch) {
                Inventory::create([
                    'product_id' => $product->id,
                    'branch_id' => $branch->id,
                    'quantity' => 0,
                ]);
            }

            return $product;
        });

        return response()->json(['data' => new ProductResource($product->load(['category', 'brand']))], 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);

        $branchId = $request->user()->branch_id;
        $product->load(['category', 'brand', 'inventories.branch']);

        return response()->json(['data' => new ProductResource($product)]);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);
        $data = $request->validated();

        if (TenantSettings::string($request->user()->tenant_id, 'installment_pricing_mode', 'percentage') === 'fixed') {
            $durations = $data['allowed_durations'] ?? $product->allowed_durations ?? [];
            $months = $durations ? min(array_map('intval', $durations)) : 12;
            $minDown = (float) ($data['min_down_payment'] ?? $product->min_down_payment);
            $fm = (float) ($data['fixed_monthly_amount'] ?? $product->fixed_monthly_amount);
            if ($fm > 0 && $months > 0) {
                $data['installment_price'] = round($minDown + $fm * $months, 2);
            }
        }

        $product->update($data);

        return response()->json(['data' => new ProductResource($product->fresh(['category', 'brand']))]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);
        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    public function adjustStock(Request $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);

        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'quantity' => 'required|integer',
            'type' => 'required|in:in,out,adjustment',
            'notes' => 'nullable|string',
        ]);

        $inventory = Inventory::firstOrCreate(
            ['product_id' => $product->id, 'branch_id' => $request->branch_id],
            ['quantity' => 0]
        );

        $before = $inventory->quantity;

        match ($request->type) {
            'in' => $inventory->increment('quantity', abs($request->quantity)),
            'out' => $inventory->decrement('quantity', abs($request->quantity)),
            'adjustment' => $inventory->update(['quantity' => $request->quantity]),
        };

        $inventory->refresh();

        StockMovement::create([
            'product_id' => $product->id,
            'branch_id' => $request->branch_id,
            'created_by' => $request->user()->id,
            'type' => $request->type,
            'quantity' => abs($request->quantity),
            'quantity_before' => $before,
            'quantity_after' => $inventory->quantity,
            'notes' => $request->notes,
        ]);

        return response()->json(['data' => $inventory]);
    }

    private function authorizeTenant(Request $request, Product $product): void
    {
        if (!$request->user()->isSuperAdmin() && $product->tenant_id !== $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }
    }
}
