<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreBranchRequest;
use App\Http\Requests\Branch\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Services\TenantSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function __construct(
        private readonly TenantSubscriptionService $tenantSubscriptionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $branches = Branch::forTenant($tenantId)
            ->when($request->boolean('active_only'), fn($q) => $q->active())
            ->withCount('users')
            ->get();

        return response()->json(['data' => BranchResource::collection($branches)]);
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $this->tenantSubscriptionService->assertCanCreateBranch((int) $request->user()->tenant_id);

        $branch = Branch::create([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
        ]);

        return response()->json(['data' => new BranchResource($branch)], 201);
    }

    public function show(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeTenant($request, $branch);
        $branch->loadCount(['users', 'orders', 'contracts']);

        return response()->json(['data' => new BranchResource($branch)]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $this->authorizeTenant($request, $branch);
        $branch->update($request->validated());

        return response()->json(['data' => new BranchResource($branch->fresh())]);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeTenant($request, $branch);

        if ($branch->is_main) {
            return response()->json(['message' => 'Cannot delete the main branch.'], 422);
        }

        $branch->delete();
        return response()->json(['message' => 'Branch deleted.']);
    }

    private function authorizeTenant(Request $request, Branch $branch): void
    {
        if (!$request->user()->isSuperAdmin() && $branch->tenant_id !== $request->user()->tenant_id) {
            abort(403);
        }
    }
}
