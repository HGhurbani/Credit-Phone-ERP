<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cashbox\StoreCashboxRequest;
use App\Http\Requests\Cashbox\StoreManualAdjustmentRequest;
use App\Http\Requests\Cashbox\StoreManualTransactionRequest;
use App\Http\Requests\Cashbox\UpdateCashboxRequest;
use App\Http\Resources\CashboxResource;
use App\Http\Resources\CashTransactionResource;
use App\Models\Cashbox;
use App\Services\CashboxService;
use App\Support\TenantBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashboxController extends Controller
{
    public function __construct(
        private readonly CashboxService $cashboxService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $query = Cashbox::forTenant($tenantId)
            ->with('branch')
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch))
            ->orderBy('name');

        return response()->json(['data' => CashboxResource::collection($query->get())]);
    }

    public function store(StoreCashboxRequest $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        TenantBranchScope::assertBranchBelongsToTenant((int) $request->branch_id, $tenantId);
        if (TenantBranchScope::isBranchScoped($user) && (int) $user->branch_id !== (int) $request->branch_id) {
            abort(403, 'Access denied.');
        }

        $opening = (float) ($request->opening_balance ?? 0);
        $branchId = (int) $request->branch_id;

        $cashbox = DB::transaction(function () use ($request, $tenantId, $opening, $branchId) {
            $wantsPrimary = $request->has('is_primary')
                ? (bool) $request->boolean('is_primary')
                : null;

            $hasAny = Cashbox::forTenant($tenantId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->exists();

            $isPrimary = $wantsPrimary ?? (! $hasAny);

            if ($isPrimary) {
                Cashbox::forTenant($tenantId)
                    ->where('branch_id', $branchId)
                    ->where('is_primary', true)
                    ->lockForUpdate()
                    ->update(['is_primary' => false]);
            }

            return Cashbox::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'name' => $request->name,
                'type' => $request->input('type'),
                'opening_balance' => $opening,
                'current_balance' => $opening,
                'is_active' => $request->boolean('is_active', true),
                'is_primary' => $isPrimary,
            ]);
        });

        return response()->json(['data' => new CashboxResource($cashbox->load('branch'))], 201);
    }

    public function show(Request $request, Cashbox $cashbox): JsonResponse
    {
        $this->authorizeCashbox($request, $cashbox);

        return response()->json(['data' => new CashboxResource($cashbox->load('branch'))]);
    }

    public function update(UpdateCashboxRequest $request, Cashbox $cashbox): JsonResponse
    {
        $this->authorizeCashbox($request, $cashbox);

        $data = $request->validated();

        if (array_key_exists('is_primary', $data) && (bool) $data['is_primary'] === true) {
            DB::transaction(function () use ($cashbox, $data) {
                $locked = Cashbox::whereKey($cashbox->id)->lockForUpdate()->firstOrFail();

                Cashbox::forTenant((int) $locked->tenant_id)
                    ->where('branch_id', (int) $locked->branch_id)
                    ->where('id', '!=', (int) $locked->id)
                    ->where('is_primary', true)
                    ->lockForUpdate()
                    ->update(['is_primary' => false]);

                $locked->update($data);
            });
        } else {
            $cashbox->update($data);
        }

        return response()->json(['data' => new CashboxResource($cashbox->fresh()->load('branch'))]);
    }

    public function destroy(Request $request, Cashbox $cashbox): JsonResponse
    {
        $this->authorizeCashbox($request, $cashbox);

        if ($cashbox->cashTransactions()->exists()) {
            throw ValidationException::withMessages([
                'cashbox' => ['Cannot delete a cashbox that has transactions.'],
            ]);
        }

        $cashbox->delete();

        return response()->json(['message' => 'Cashbox deleted.']);
    }

    public function storeTransaction(StoreManualTransactionRequest $request, Cashbox $cashbox): JsonResponse
    {
        $this->authorizeCashbox($request, $cashbox);

        $tx = $this->cashboxService->recordManual(
            $cashbox,
            $request->validated(),
            $request->user()->id
        );

        return response()->json(['data' => new CashTransactionResource($tx->load(['cashbox', 'branch', 'createdBy']))], 201);
    }

    public function storeAdjustment(StoreManualAdjustmentRequest $request, Cashbox $cashbox): JsonResponse
    {
        $this->authorizeCashbox($request, $cashbox);

        $tx = $this->cashboxService->recordManualAdjustment(
            $cashbox,
            $request->validated(),
            $request->user()->id
        );

        return response()->json(['data' => new CashTransactionResource($tx->load(['cashbox', 'branch', 'createdBy']))], 201);
    }

    private function authorizeCashbox(Request $request, Cashbox $cashbox): void
    {
        if (! $request->user()->isSuperAdmin() && (int) $cashbox->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }

        if (TenantBranchScope::isBranchScoped($request->user())
            && (int) $cashbox->branch_id !== (int) $request->user()->branch_id) {
            abort(403, 'Access denied.');
        }
    }
}
