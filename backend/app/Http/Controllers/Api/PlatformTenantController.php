<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesSuperAdmin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformTenantController extends Controller
{
    use AuthorizesSuperAdmin;

    public function __construct(
        private readonly TenantProvisioningService $tenantProvisioningService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $tenants = Tenant::query()
            ->withCount(['branches', 'users', 'subscriptions'])
            ->with(['latestSubscription.plan'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->trim()->value();

                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('domain', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return response()->json([
            'data' => TenantResource::collection($tenants->items()),
            'meta' => [
                'total' => $tenants->total(),
                'per_page' => $tenants->perPage(),
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
            ],
        ]);
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $validated = $request->validated();
        $plan = isset($validated['plan_id']) ? SubscriptionPlan::find($validated['plan_id']) : null;

        $tenant = $this->tenantProvisioningService->provision(
            tenantData: array_diff_key($validated, array_flip([
                'admin_name',
                'admin_email',
                'admin_phone',
                'admin_password',
                'plan_id',
                'main_branch_name',
            ])),
            adminData: [
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'phone' => $validated['admin_phone'] ?? null,
                'password' => $validated['admin_password'],
            ],
            plan: $plan,
        );

        return response()->json([
            'data' => new TenantResource(
                $tenant->load(['latestSubscription.plan'])->loadCount(['branches', 'users', 'subscriptions'])
            ),
        ], 201);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $tenant->update($request->validated());

        return response()->json([
            'data' => new TenantResource(
                $tenant->fresh()->load(['latestSubscription.plan'])->loadCount(['branches', 'users', 'subscriptions'])
            ),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $tenant->delete();

        return response()->json(['message' => 'Tenant deleted.']);
    }
}
