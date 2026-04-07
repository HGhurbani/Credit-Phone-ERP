<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expense\StoreExpenseRequest;
use App\Http\Requests\Expense\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseService;
use App\Support\TenantBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenseService,
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

        $query = Expense::forTenant($tenantId)
            ->with(['branch', 'cashbox', 'createdBy'])
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch))
            ->when($request->category, fn ($q) => $q->where('category', $request->category))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->date_from, fn ($q) => $q->whereDate('expense_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('expense_date', '<=', $request->date_to))
            ->latest('expense_date')
            ->latest('id');

        $paginator = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'data' => ExpenseResource::collection($paginator->items()),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        TenantBranchScope::assertBranchBelongsToTenant((int) $request->branch_id, $tenantId);
        if (TenantBranchScope::isBranchScoped($user) && (int) $user->branch_id !== (int) $request->branch_id) {
            abort(403, 'Access denied.');
        }

        $expense = $this->expenseService->create($request->validated(), $tenantId, $user->id);

        return response()->json(['data' => new ExpenseResource($expense)], 201);
    }

    public function show(Request $request, Expense $expense): JsonResponse
    {
        $this->authorizeExpense($request, $expense);

        return response()->json(['data' => new ExpenseResource($expense->load(['branch', 'cashbox', 'createdBy']))]);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorizeExpense($request, $expense);

        $expense = $this->expenseService->updateMetadata($expense, $request->validated());

        return response()->json(['data' => new ExpenseResource($expense)]);
    }

    public function cancel(Request $request, Expense $expense): JsonResponse
    {
        $this->authorizeExpense($request, $expense);

        $expense = $this->expenseService->cancel($expense, $request->user()->id);

        return response()->json(['data' => new ExpenseResource($expense)]);
    }

    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        $this->authorizeExpense($request, $expense);

        $this->expenseService->deleteIfAllowed($expense);

        return response()->json(['message' => 'Expense deleted.']);
    }

    private function authorizeExpense(Request $request, Expense $expense): void
    {
        if (! $request->user()->isSuperAdmin() && (int) $expense->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403, 'Access denied.');
        }

        if (TenantBranchScope::isBranchScoped($request->user())
            && (int) $expense->branch_id !== (int) $request->user()->branch_id) {
            abort(403, 'Access denied.');
        }
    }
}
