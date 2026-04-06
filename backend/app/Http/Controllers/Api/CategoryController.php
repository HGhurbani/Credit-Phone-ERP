<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $categories = Category::forTenant($tenantId)->orderBy('name')->get();

        return response()->json(['data' => $categories]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
        ]);

        return response()->json(['data' => $category], 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorizeTenant($request, $category);

        $category->update($request->validated());

        return response()->json(['data' => $category->fresh()]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorizeTenant($request, $category);
        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    private function authorizeTenant(Request $request, Category $category): void
    {
        if ($request->user()->isSuperAdmin()) {
            return;
        }

        if ((int) $category->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }
    }
}
