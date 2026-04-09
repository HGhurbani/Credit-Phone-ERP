<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesSuperAdmin;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformImpersonationController extends Controller
{
    use AuthorizesSuperAdmin;

    public function __construct(
        private readonly TenantProvisioningService $tenantProvisioningService,
    ) {}

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $tenantAdmin = $this->tenantProvisioningService->ensureCompanyAdmin($tenant);
        $token = $tenantAdmin->createToken('platform-impersonation')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($tenantAdmin),
        ]);
    }
}
