<?php

namespace App\Services\Assistant;

use App\Models\CashTransaction;
use App\Models\InstallmentContract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerStatementService;
use App\Support\TenantBranchScope;
use App\Support\TenantSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class AssistantPdfService
{
    public function __construct(
        private readonly CustomerStatementService $customerStatementService,
    ) {}

    public function temporarySignedUrl(User $user, string $type, int $recordId, string $filename): string
    {
        return URL::temporarySignedRoute(
            'assistant.print.download',
            now()->addMinutes(30),
            [
                'type' => $type,
                'record' => $recordId,
                'filename' => $filename,
                'tenant' => $user->tenant_id,
                'user' => $user->id,
            ]
        );
    }

    public function download(User $user, string $type, int $recordId, string $filename): Response
    {
        $document = $this->buildDocument($user, $type, $recordId);
        $pdf = Pdf::loadView($document['view'], [
            'locale' => $user->locale ?? 'ar',
            'rtl' => ($user->locale ?? 'ar') !== 'en',
            'company' => $this->companyProfile($user),
            'title' => $document['title'],
            'subtitle' => $document['subtitle'],
            'payload' => $document['payload'],
            'generatedAt' => now(),
        ])->setPaper('a4');

        return $pdf->download($filename);
    }

    private function buildDocument(User $user, string $type, int $recordId): array
    {
        return match ($type) {
            'customer_statement' => $this->buildCustomerStatement($user, $recordId),
            'contract' => $this->buildContract($user, $recordId),
            'invoice' => $this->buildInvoice($user, $recordId),
            'payment_receipt' => $this->buildPaymentReceipt($user, $recordId),
            'purchase_order' => $this->buildPurchaseOrder($user, $recordId),
            'cash_voucher' => $this->buildCashVoucher($user, $recordId),
            default => abort(404),
        };
    }

    private function buildCustomerStatement(User $user, int $recordId): array
    {
        $customer = $this->scopedCustomers($user)->findOrFail($recordId);

        return [
            'view' => 'assistant.pdf.statement',
            'title' => $this->loc($user, 'كشف حساب العميل', 'Customer Statement'),
            'subtitle' => $customer->name,
            'payload' => $this->customerStatementService->build($customer),
        ];
    }

    private function buildContract(User $user, int $recordId): array
    {
        $contract = InstallmentContract::query()
            ->where('tenant_id', $user->tenant_id)
            ->when(TenantBranchScope::isBranchScoped($user), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->with(['customer', 'branch', 'schedules'])
            ->findOrFail($recordId);

        return [
            'view' => 'assistant.pdf.contract',
            'title' => $this->loc($user, 'العقد', 'Contract'),
            'subtitle' => $contract->contract_number,
            'payload' => $contract,
        ];
    }

    private function buildInvoice(User $user, int $recordId): array
    {
        $invoice = Invoice::query()
            ->where('tenant_id', $user->tenant_id)
            ->when(TenantBranchScope::isBranchScoped($user), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->with(['customer', 'branch', 'contract', 'items'])
            ->findOrFail($recordId);

        return [
            'view' => 'assistant.pdf.invoice',
            'title' => $this->loc($user, 'الفاتورة', 'Invoice'),
            'subtitle' => $invoice->invoice_number,
            'payload' => $invoice,
        ];
    }

    private function buildPaymentReceipt(User $user, int $recordId): array
    {
        $payment = Payment::query()
            ->where('tenant_id', $user->tenant_id)
            ->when(TenantBranchScope::isBranchScoped($user), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->with(['customer', 'contract', 'schedule', 'collectedBy', 'branch', 'invoice'])
            ->findOrFail($recordId);

        return [
            'view' => 'assistant.pdf.payment',
            'title' => $this->loc($user, 'إيصال الدفع', 'Payment Receipt'),
            'subtitle' => $payment->receipt_number ?: '#'.$payment->id,
            'payload' => $payment,
        ];
    }

    private function buildPurchaseOrder(User $user, int $recordId): array
    {
        $purchaseOrder = PurchaseOrder::query()
            ->where('tenant_id', $user->tenant_id)
            ->when(TenantBranchScope::isBranchScoped($user), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->with([
                'supplier',
                'branch',
                'items.product',
                'goodsReceipts.branch',
                'goodsReceipts.receivedBy',
                'goodsReceipts.items.purchaseOrderItem.product',
            ])
            ->findOrFail($recordId);

        return [
            'view' => 'assistant.pdf.purchase-order',
            'title' => $this->loc($user, 'أمر الشراء', 'Purchase Order'),
            'subtitle' => $purchaseOrder->purchase_number,
            'payload' => $purchaseOrder,
        ];
    }

    private function buildCashVoucher(User $user, int $recordId): array
    {
        $cashTransaction = CashTransaction::query()
            ->where('tenant_id', $user->tenant_id)
            ->when(TenantBranchScope::isBranchScoped($user), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->with(['cashbox', 'branch', 'createdBy'])
            ->findOrFail($recordId);

        return [
            'view' => 'assistant.pdf.cash-voucher',
            'title' => $cashTransaction->direction === 'in'
                ? $this->loc($user, 'سند قبض', 'Receipt Voucher')
                : $this->loc($user, 'سند صرف', 'Payment Voucher'),
            'subtitle' => $cashTransaction->voucher_number,
            'payload' => $cashTransaction,
        ];
    }

    private function scopedCustomers(User $user)
    {
        return \App\Models\Customer::query()
            ->where('tenant_id', $user->tenant_id)
            ->when(TenantBranchScope::isBranchScoped($user), fn ($query) => $query->where('branch_id', $user->branch_id));
    }

    private function companyProfile(User $user): array
    {
        $tenant = $user->tenant()->first() ?: Tenant::query()->find($user->tenant_id);

        return [
            'name' => TenantSettings::string($user->tenant_id, 'company_name', $tenant?->name ?? ''),
            'phone' => TenantSettings::string($user->tenant_id, 'company_phone', $tenant?->phone ?? ''),
            'email' => TenantSettings::string($user->tenant_id, 'company_email', $tenant?->email ?? ''),
            'address' => TenantSettings::string($user->tenant_id, 'company_address', $tenant?->address ?? ''),
            'cr_number' => TenantSettings::string($user->tenant_id, 'company_cr_number', ''),
            'license_number' => TenantSettings::string($user->tenant_id, 'company_license_number', ''),
            'tax_card_number' => TenantSettings::string($user->tenant_id, 'company_tax_card_number', ''),
        ];
    }

    private function loc(User $user, string $ar, string $en): string
    {
        return ($user->locale ?? 'ar') === 'en' ? $en : $ar;
    }
}
