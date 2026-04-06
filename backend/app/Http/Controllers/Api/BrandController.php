<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Brand\StoreBrandRequest;
use App\Http\Requests\Brand\UpdateBrandRequest;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $brands = Brand::forTenant($tenantId)->orderBy('name')->get();

        return response()->json(['data' => $brands]);
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand = Brand::create([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
        ]);

        return response()->json(['data' => $brand], 201);
    }

    public function update(UpdateBrandRequest $request, Brand $brand): JsonResponse
    {
        $this->authorizeTenant($request, $brand);

        $brand->update($request->validated());

        return response()->json(['data' => $brand->fresh()]);
    }

    public function destroy(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizeTenant($request, $brand);
        $brand->delete();

        return response()->json(['message' => 'Brand deleted.']);
    }

    private function authorizeTenant(Request $request, Brand $brand): void
    {
        if ($request->user()->isSuperAdmin()) {
            return;
        }

        if ((int) $brand->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }
    }
}
