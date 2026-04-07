<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Customer;
use App\Support\TenantBranchScope;
use Illuminate\Http\Request;

trait AuthorizesCustomerAccess
{
    protected function authorizeCustomerAccess(Request $request, Customer $customer): void
    {
        if (! $request->user()->isSuperAdmin() && $customer->tenant_id !== $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }

        if (TenantBranchScope::isBranchScoped($request->user())
            && (int) $customer->branch_id !== (int) $request->user()->branch_id) {
            abort(403, 'Access denied.');
        }
    }
}
