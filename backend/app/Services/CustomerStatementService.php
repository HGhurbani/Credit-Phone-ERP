<?php

namespace App\Services;

use App\Models\CollectionFollowUp;
use App\Models\Customer;
use App\Models\InstallmentSchedule;
use App\Models\PromiseToPay;
use App\Models\RescheduleRequest;

class CustomerStatementService
{
    public function build(Customer $customer): array
    {
        $customer->loadMissing('branch');
        $tenantId = $customer->tenant_id;

        $activeContracts = $customer->contracts()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderByDesc('created_at')
            ->get();

        $contractIds = $activeContracts->pluck('id');

        $overdueInstallments = InstallmentSchedule::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'overdue')
            ->when($contractIds->isEmpty(), fn ($q) => $q->whereRaw('1 = 0'), fn ($q) => $q->whereIn('contract_id', $contractIds))
            ->with(['contract:id,contract_number,status'])
            ->orderBy('due_date')
            ->get();

        $invoiceBalanceTotal = (float) $customer->invoices()
            ->whereIn('status', ['unpaid', 'partial'])
            ->sum('remaining_amount');

        $openInvoices = $customer->invoices()
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderByDesc('issue_date')
            ->limit(30)
            ->get(['id', 'invoice_number', 'status', 'total', 'paid_amount', 'remaining_amount', 'issue_date', 'due_date', 'type']);

        $installmentsOutstanding = (float) $activeContracts->sum('remaining_amount');

        $totalPaid = (float) $customer->payments()->sum('amount');

        $latestPayments = $customer->payments()
            ->with(['contract:id,contract_number', 'invoice:id,invoice_number'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $latestCustomerNotes = $customer->notes()
            ->with('createdBy:id,name')
            ->latest()
            ->limit(10)
            ->get();

        $latestFollowUps = CollectionFollowUp::query()
            ->where('customer_id', $customer->id)
            ->with(['createdBy:id,name', 'contract:id,contract_number'])
            ->latest()
            ->limit(15)
            ->get();

        $activePromises = PromiseToPay::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'active')
            ->with(['contract:id,contract_number'])
            ->orderBy('promised_date')
            ->limit(20)
            ->get();

        $pendingReschedules = RescheduleRequest::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'pending')
            ->with(['contract:id,contract_number'])
            ->latest()
            ->limit(10)
            ->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'national_id' => $customer->national_id,
                'address' => $customer->address,
                'city' => $customer->city,
                'branch_id' => $customer->branch_id,
                'branch' => $customer->branch
                    ? ['id' => $customer->branch->id, 'name' => $customer->branch->name]
                    : null,
            ],
            'summary' => [
                'installments_outstanding' => round($installmentsOutstanding, 2),
                'invoice_balance' => round($invoiceBalanceTotal, 2),
                'total_outstanding' => round($installmentsOutstanding + $invoiceBalanceTotal, 2),
                'total_paid' => round($totalPaid, 2),
            ],
            'active_contracts' => $activeContracts->map(fn ($c) => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'status' => $c->status,
                'total_amount' => (string) $c->total_amount,
                'paid_amount' => (string) $c->paid_amount,
                'remaining_amount' => (string) $c->remaining_amount,
                'monthly_amount' => (string) $c->monthly_amount,
                'end_date' => $c->end_date?->toDateString(),
            ])->values()->all(),
            'overdue_installments' => $overdueInstallments->map(fn ($s) => [
                'id' => $s->id,
                'contract_id' => $s->contract_id,
                'contract_number' => $s->contract?->contract_number,
                'installment_number' => $s->installment_number,
                'due_date' => $s->due_date?->toDateString(),
                'amount' => (string) $s->amount,
                'remaining_amount' => (string) $s->remaining_amount,
                'status' => $s->status,
            ])->values()->all(),
            'open_invoices' => $openInvoices->map(fn ($inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'status' => $inv->status,
                'type' => $inv->type,
                'total' => (string) $inv->total,
                'paid_amount' => (string) $inv->paid_amount,
                'remaining_amount' => (string) $inv->remaining_amount,
                'issue_date' => $inv->issue_date?->toDateString(),
                'due_date' => $inv->due_date?->toDateString(),
            ])->values()->all(),
            'latest_payments' => $latestPayments->map(fn ($p) => [
                'id' => $p->id,
                'amount' => (string) $p->amount,
                'payment_method' => $p->payment_method,
                'payment_date' => $p->payment_date?->toDateString(),
                'contract_number' => $p->contract?->contract_number,
                'invoice_number' => $p->invoice?->invoice_number,
                'receipt_number' => $p->receipt_number,
            ])->values()->all(),
            'latest_customer_notes' => $latestCustomerNotes->map(fn ($n) => [
                'id' => $n->id,
                'note' => $n->note,
                'created_by' => $n->createdBy?->name,
                'created_at' => $n->created_at?->toDateTimeString(),
            ])->values()->all(),
            'latest_collection_follow_ups' => $latestFollowUps->map(fn ($f) => [
                'id' => $f->id,
                'outcome' => $f->outcome,
                'priority' => $f->priority,
                'next_follow_up_date' => $f->next_follow_up_date?->toDateString(),
                'note' => $f->note,
                'contract_number' => $f->contract?->contract_number,
                'created_by' => $f->createdBy?->name,
                'created_at' => $f->created_at?->toDateTimeString(),
            ])->values()->all(),
            'active_promises_to_pay' => $activePromises->map(fn ($p) => [
                'id' => $p->id,
                'promised_amount' => (string) $p->promised_amount,
                'promised_date' => $p->promised_date?->toDateString(),
                'note' => $p->note,
                'contract_number' => $p->contract?->contract_number,
            ])->values()->all(),
            'pending_reschedule_requests' => $pendingReschedules->map(fn ($r) => [
                'id' => $r->id,
                'note' => $r->note,
                'contract_number' => $r->contract?->contract_number,
                'created_at' => $r->created_at?->toDateTimeString(),
            ])->values()->all(),
        ];
    }
}
