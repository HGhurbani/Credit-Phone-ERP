<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-wide vs branch-scoped access for API queries and mutations.
 *
 * Company admins and super admins may filter by branch when branch_id belongs to the tenant.
 * Other users with a branch_id are restricted to that branch; request branch_id is ignored.
 * Users without a branch (e.g. some accountants) may use an optional validated branch filter.
 */
class TenantBranchScope
{
    public static function isBranchScoped(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return false;
        }
        if ($user->isCompanyAdmin()) {
            return false;
        }

        return $user->branch_id !== null;
    }

    /**
     * Ensure a branch exists and belongs to the given tenant.
     */
    public static function assertBranchBelongsToTenant(int $branchId, int $tenantId): void
    {
        $exists = Branch::where('id', $branchId)->where('tenant_id', $tenantId)->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'branch_id' => ['Invalid branch for this tenant.'],
            ]);
        }
    }

    /**
     * Effective branch filter for list/index/report queries.
     * Returns null when the whole tenant should be included (no branch filter).
     */
    public static function resolveListBranchId(User $user, ?int $requestBranchId, ?int $tenantId): ?int
    {
        if ($user->isSuperAdmin() || $user->isCompanyAdmin()) {
            if ($requestBranchId !== null && $tenantId !== null) {
                self::assertBranchBelongsToTenant($requestBranchId, $tenantId);
            }

            return $requestBranchId;
        }

        if (self::isBranchScoped($user)) {
            return $user->branch_id !== null ? (int) $user->branch_id : null;
        }

        if ($requestBranchId !== null && $tenantId !== null) {
            self::assertBranchBelongsToTenant($requestBranchId, $tenantId);
        }

        return $requestBranchId;
    }

    public static function requestBranchId(Request $request): ?int
    {
        if (! $request->filled('branch_id')) {
            return null;
        }

        return (int) $request->branch_id;
    }

    /**
     * Branch must belong to product tenant; branch-scoped users may only use their own branch.
     */
    public static function assertBranchAccessibleForStock(User $user, int $branchId, int $productTenantId): void
    {
        self::assertBranchBelongsToTenant($branchId, $productTenantId);

        if (self::isBranchScoped($user) && (int) $user->branch_id !== $branchId) {
            abort(403, 'Access denied.');
        }
    }
}
