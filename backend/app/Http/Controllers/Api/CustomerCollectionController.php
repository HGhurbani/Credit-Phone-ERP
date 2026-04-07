<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesCustomerAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collections\StoreCollectionFollowUpRequest;
use App\Http\Requests\Collections\StorePromiseToPayRequest;
use App\Http\Requests\Collections\StoreRescheduleRequestRequest;
use App\Models\CollectionFollowUp;
use App\Models\Customer;
use App\Models\InstallmentContract;
use App\Models\PromiseToPay;
use App\Models\RescheduleRequest;
use App\Services\CustomerStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class CustomerCollectionController extends Controller
{
    use AuthorizesCustomerAccess;

    public function __construct(
        private readonly CustomerStatementService $statementService
    ) {}

    public function statement(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomerAccess($request, $customer);

        return response()->json([
            'data' => $this->statementService->build($customer),
        ]);
    }

    public function followUpsIndex(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomerAccess($request, $customer);

        $query = CollectionFollowUp::query()
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->with(['createdBy:id,name', 'contract:id,contract_number'])
            ->latest();

        if ($request->filled('contract_id')) {
            $query->where('contract_id', (int) $request->contract_id);
        }

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($request->integer('per_page', 30));

        return response()->json([
            'data' => $page->through(fn (CollectionFollowUp $f) => $this->formatFollowUp($f))->items(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function promisesIndex(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomerAccess($request, $customer);

        $query = PromiseToPay::query()
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->with(['createdBy:id,name', 'contract:id,contract_number'])
            ->latest();

        if ($request->filled('contract_id')) {
            $query->where('contract_id', (int) $request->contract_id);
        }

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($request->integer('per_page', 30));

        return response()->json([
            'data' => $page->through(fn (PromiseToPay $p) => $this->formatPromise($p))->items(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function rescheduleIndex(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomerAccess($request, $customer);

        $query = RescheduleRequest::query()
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->with(['createdBy:id,name', 'contract:id,contract_number'])
            ->latest();

        if ($request->filled('contract_id')) {
            $query->where('contract_id', (int) $request->contract_id);
        }

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($request->integer('per_page', 30));

        return response()->json([
            'data' => $page->through(fn (RescheduleRequest $r) => $this->formatReschedule($r))->items(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function storeFollowUp(StoreCollectionFollowUpRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomerAccess($request, $customer);
        $data = $request->validated();
        $contractId = isset($data['contract_id']) ? (int) $data['contract_id'] : null;
        $this->assertContractBelongsToCustomer($contractId, $customer);

        $followUp = CollectionFollowUp::create([
            'tenant_id' => $customer->tenant_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'contract_id' => $contractId,
            'outcome' => $data['outcome'],
            'next_follow_up_date' => $data['next_follow_up_date'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $followUp->load(['createdBy:id,name', 'contract:id,contract_number']);

        return response()->json(['data' => $this->formatFollowUp($followUp)], 201);
    }

    public function storePromiseToPay(StorePromiseToPayRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomerAccess($request, $customer);
        $data = $request->validated();
        $contractId = isset($data['contract_id']) ? (int) $data['contract_id'] : null;
        $this->assertContractBelongsToCustomer($contractId, $customer);

        $promise = PromiseToPay::create([
            'tenant_id' => $customer->tenant_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'contract_id' => $contractId,
            'promised_amount' => $data['promised_amount'],
            'promised_date' => $data['promised_date'],
            'note' => $data['note'] ?? null,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        $promise->load(['createdBy:id,name', 'contract:id,contract_number']);

        return response()->json(['data' => $this->formatPromise($promise)], 201);
    }

    public function storeRescheduleRequest(StoreRescheduleRequestRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomerAccess($request, $customer);
        $data = $request->validated();
        $contractId = (int) $data['contract_id'];
        $this->assertContractBelongsToCustomer($contractId, $customer);

        $row = RescheduleRequest::create([
            'tenant_id' => $customer->tenant_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'contract_id' => $contractId,
            'note' => $data['note'] ?? null,
            'status' => 'pending',
            'created_by' => $request->user()->id,
        ]);

        $row->load(['createdBy:id,name', 'contract:id,contract_number']);

        return response()->json(['data' => $this->formatReschedule($row)], 201);
    }

    private function assertContractBelongsToCustomer(?int $contractId, Customer $customer): void
    {
        if ($contractId === null) {
            return;
        }

        $exists = InstallmentContract::query()
            ->where('id', $contractId)
            ->where('customer_id', $customer->id)
            ->where('tenant_id', $customer->tenant_id)
            ->exists();

        if (! $exists) {
            abort(422, 'Contract does not belong to this customer.');
        }
    }

    private function formatFollowUp(CollectionFollowUp $f): array
    {
        return [
            'id' => $f->id,
            'outcome' => $f->outcome,
            'priority' => $f->priority,
            'next_follow_up_date' => $f->next_follow_up_date?->toDateString(),
            'note' => $f->note,
            'contract_id' => $f->contract_id,
            'contract_number' => $f->contract?->contract_number,
            'created_by' => $f->createdBy?->name,
            'created_at' => $f->created_at?->toDateTimeString(),
        ];
    }

    private function formatPromise(PromiseToPay $p): array
    {
        return [
            'id' => $p->id,
            'promised_amount' => (string) $p->promised_amount,
            'promised_date' => $p->promised_date?->toDateString(),
            'note' => $p->note,
            'status' => $p->status,
            'contract_id' => $p->contract_id,
            'contract_number' => $p->contract?->contract_number,
            'created_by' => $p->createdBy?->name,
            'created_at' => $p->created_at?->toDateTimeString(),
        ];
    }

    private function formatReschedule(RescheduleRequest $r): array
    {
        return [
            'id' => $r->id,
            'contract_id' => $r->contract_id,
            'contract_number' => $r->contract?->contract_number,
            'note' => $r->note,
            'status' => $r->status,
            'created_by' => $r->createdBy?->name,
            'created_at' => $r->created_at?->toDateTimeString(),
        ];
    }
}
