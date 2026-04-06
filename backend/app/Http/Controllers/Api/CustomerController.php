<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Support\TenantBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
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

        $query = Customer::forTenant($tenantId)
            ->with(['branch', 'createdBy'])
            ->when($request->search, fn ($q) => $q->search($request->search))
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch))
            ->when($request->credit_score, fn ($q) => $q->where('credit_score', $request->credit_score))
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->latest();

        $customers = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => CustomerResource::collection($customers->items()),
            'meta' => [
                'total' => $customers->total(),
                'per_page' => $customers->perPage(),
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
            'branch_id' => $request->user()->branch_id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => new CustomerResource($customer)], 201);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeTenant($request, $customer);

        $customer->load([
            'branch', 'createdBy', 'guarantors', 'documents', 'notes.createdBy',
            'orders' => fn($q) => $q->latest()->limit(10),
            'contracts' => fn($q) => $q->latest()->limit(5),
            'payments' => fn($q) => $q->latest()->limit(10),
        ]);

        return response()->json(['data' => new CustomerResource($customer)]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeTenant($request, $customer);
        $customer->update($request->validated());

        return response()->json(['data' => new CustomerResource($customer->fresh())]);
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeTenant($request, $customer);
        $customer->delete();

        return response()->json(['message' => 'Customer deleted.']);
    }

    public function addNote(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeTenant($request, $customer);

        $request->validate(['note' => 'required|string|max:2000']);

        $note = CustomerNote::create([
            'customer_id' => $customer->id,
            'created_by' => $request->user()->id,
            'note' => $request->note,
        ]);

        return response()->json(['data' => $note->load('createdBy')], 201);
    }

    private function authorizeTenant(Request $request, Customer $customer): void
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
