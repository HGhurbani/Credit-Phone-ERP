<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CashTransactionResource;
use App\Models\CashTransaction;
use App\Support\TenantBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $query = CashTransaction::forTenant($tenantId)
            ->with(['cashbox', 'branch', 'createdBy'])
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch))
            ->when($request->cashbox_id, fn ($q) => $q->where('cashbox_id', $request->cashbox_id))
            ->when($request->transaction_type, fn ($q) => $q->where('transaction_type', $request->transaction_type))
            ->when($request->direction, fn ($q) => $q->where('direction', $request->direction))
            ->when($request->date_from, fn ($q) => $q->whereDate('transaction_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('transaction_date', '<=', $request->date_to))
            ->latest('transaction_date')
            ->latest('id');

        $paginator = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'data' => CashTransactionResource::collection($paginator->items()),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, CashTransaction $cashTransaction): JsonResponse
    {
        if (! $request->user()->isSuperAdmin() && (int) $cashTransaction->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }

        if (TenantBranchScope::isBranchScoped($request->user())
            && (int) $cashTransaction->branch_id !== (int) $request->user()->branch_id) {
            abort(403, 'Access denied.');
        }

        $cashTransaction->load(['cashbox.branch', 'branch', 'createdBy']);

        return response()->json(['data' => new CashTransactionResource($cashTransaction)]);
    }
}
