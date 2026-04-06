<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\StoreInvoicePaymentRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Support\TenantBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $effectiveBranch = TenantBranchScope::resolveListBranchId(
            $user,
            TenantBranchScope::requestBranchId($request),
            $tenantId
        );

        $invoices = Invoice::where('tenant_id', $tenantId)
            ->with(['customer', 'branch', 'order'])
            ->when($effectiveBranch !== null, fn ($q) => $q->where('branch_id', $effectiveBranch))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->customer_id, fn ($q) => $q->where('customer_id', $request->customer_id))
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => InvoiceResource::collection($invoices->items()),
            'meta' => [
                'total' => $invoices->total(),
                'per_page' => $invoices->perPage(),
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->tenant_id !== $request->user()->tenant_id) {
            abort(403);
        }

        $this->authorizeBranchInvoice($request, $invoice);

        $invoice->load(['customer', 'branch', 'order.items.product', 'contract', 'items', 'payments']);

        return response()->json(['data' => new InvoiceResource($invoice)]);
    }

    /**
     * تسجيل دفعة وتحديث حالة الفاتورة (unpaid / partial / paid)
     */
    public function recordPayment(StoreInvoicePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->tenant_id !== $request->user()->tenant_id) {
            abort(403);
        }

        $this->authorizeBranchInvoice($request, $invoice);

        try {
            $payment = $this->invoiceService->recordPayment(
                $invoice,
                $request->validated(),
                $request->user()->id
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $invoice->load(['customer', 'branch', 'order', 'items', 'payments']);

        return response()->json([
            'data' => new InvoiceResource($invoice),
            'payment' => new PaymentResource($payment),
        ], 201);
    }

    /**
     * تغيير حالة الفاتورة إلى cancelled (لا يمكن إلغاء فاتورة مدفوعة بالكامل)
     */
    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->tenant_id !== $request->user()->tenant_id) {
            abort(403);
        }

        $this->authorizeBranchInvoice($request, $invoice);

        if ($request->input('status') === 'cancelled') {
            try {
                $invoice = $this->invoiceService->cancel($invoice);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $invoice->load(['customer', 'branch', 'order', 'items', 'payments']);

        return response()->json(['data' => new InvoiceResource($invoice)]);
    }

    private function authorizeBranchInvoice(Request $request, Invoice $invoice): void
    {
        if (! TenantBranchScope::isBranchScoped($request->user())) {
            return;
        }

        if ((int) $invoice->branch_id !== (int) $request->user()->branch_id) {
            abort(403, 'Access denied.');
        }
    }
}
