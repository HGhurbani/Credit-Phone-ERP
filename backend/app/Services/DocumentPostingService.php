<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\DocumentPosting;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\InstallmentContract;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentPostingService
{
    public function __construct(
        private readonly AccountCatalogService $accounts,
    ) {}

    public function postCashSaleInvoice(Invoice $invoice, ?int $userId = null): JournalEntry
    {
        $invoice->loadMissing(['order.items.product']);

        $lines = [
            [
                'account_key' => 'accounts_receivable_trade',
                'debit' => (float) $invoice->total,
                'credit' => 0,
                'description' => 'Invoice receivable '.$invoice->invoice_number,
            ],
            [
                'account_key' => 'sales_revenue_cash',
                'debit' => 0,
                'credit' => (float) $invoice->total,
                'description' => 'Cash sale '.$invoice->invoice_number,
            ],
        ];

        $costAmount = $this->costAmountFromOrder($invoice->order);
        if ($costAmount > 0) {
            $lines[] = [
                'account_key' => 'cost_of_goods_sold',
                'debit' => $costAmount,
                'credit' => 0,
                'description' => 'COGS '.$invoice->invoice_number,
            ];
            $lines[] = [
                'account_key' => 'inventory',
                'debit' => 0,
                'credit' => $costAmount,
                'description' => 'Inventory issued '.$invoice->invoice_number,
            ];
        }

        return $this->post(
            tenantId: (int) $invoice->tenant_id,
            branchId: $invoice->branch_id ? (int) $invoice->branch_id : null,
            event: 'cash_sale_invoice',
            source: $invoice,
            entryDate: optional($invoice->issue_date)->toDateString() ?? now()->toDateString(),
            description: 'Automatic posting for invoice '.$invoice->invoice_number,
            lineSpecs: $lines,
            userId: $userId
        );
    }

    public function reverseCashSaleInvoice(Invoice $invoice, ?int $userId = null, ?string $entryDate = null): ?JournalEntry
    {
        return $this->reverse(
            tenantId: (int) $invoice->tenant_id,
            source: $invoice,
            event: 'cash_sale_invoice',
            entryDate: $entryDate ?? now()->toDateString(),
            userId: $userId
        );
    }

    public function postInstallmentContract(InstallmentContract $contract, ?int $userId = null): JournalEntry
    {
        $contract->loadMissing(['order.items.product']);

        $lines = [
            [
                'account_key' => 'accounts_receivable_installment',
                'debit' => (float) $contract->financed_amount,
                'credit' => 0,
                'description' => 'Installment receivable '.$contract->contract_number,
            ],
            [
                'account_key' => 'sales_revenue_installment',
                'debit' => 0,
                'credit' => (float) $contract->financed_amount,
                'description' => 'Installment sale '.$contract->contract_number,
            ],
        ];

        $costAmount = $this->costAmountFromOrder($contract->order);
        if ($costAmount > 0) {
            $lines[] = [
                'account_key' => 'cost_of_goods_sold',
                'debit' => $costAmount,
                'credit' => 0,
                'description' => 'COGS '.$contract->contract_number,
            ];
            $lines[] = [
                'account_key' => 'inventory',
                'debit' => 0,
                'credit' => $costAmount,
                'description' => 'Inventory issued '.$contract->contract_number,
            ];
        }

        return $this->post(
            tenantId: (int) $contract->tenant_id,
            branchId: $contract->branch_id ? (int) $contract->branch_id : null,
            event: 'installment_contract',
            source: $contract,
            entryDate: optional($contract->start_date)->toDateString() ?? now()->toDateString(),
            description: 'Automatic posting for contract '.$contract->contract_number,
            lineSpecs: $lines,
            userId: $userId
        );
    }

    public function postContractPayment(Payment $payment, ?int $userId = null): JournalEntry
    {
        $payment->loadMissing('contract');

        return $this->postPayment(
            payment: $payment,
            event: 'contract_payment',
            receivableAccountKey: 'accounts_receivable_installment',
            referenceNumber: $payment->receipt_number,
            description: 'Automatic posting for contract payment '.$payment->receipt_number,
            userId: $userId
        );
    }

    public function postInvoicePayment(Payment $payment, ?int $userId = null): JournalEntry
    {
        $payment->loadMissing('invoice');

        return $this->postPayment(
            payment: $payment,
            event: 'invoice_payment',
            receivableAccountKey: 'accounts_receivable_trade',
            referenceNumber: $payment->receipt_number,
            description: 'Automatic posting for invoice payment '.$payment->receipt_number,
            userId: $userId
        );
    }

    public function postExpense(Expense $expense, ?int $userId = null): JournalEntry
    {
        $creditAccount = $expense->cashbox_id !== null ? 'cash_on_hand' : 'accounts_payable';

        return $this->post(
            tenantId: (int) $expense->tenant_id,
            branchId: $expense->branch_id ? (int) $expense->branch_id : null,
            event: 'expense',
            source: $expense,
            entryDate: optional($expense->expense_date)->toDateString() ?? now()->toDateString(),
            description: 'Automatic posting for expense '.$expense->expense_number,
            lineSpecs: [
                [
                    'account_key' => 'general_expense',
                    'debit' => (float) $expense->amount,
                    'credit' => 0,
                    'description' => 'Expense '.$expense->expense_number,
                ],
                [
                    'account_key' => $creditAccount,
                    'debit' => 0,
                    'credit' => (float) $expense->amount,
                    'description' => 'Expense source '.$expense->expense_number,
                ],
            ],
            userId: $userId
        );
    }

    public function reverseExpense(Expense $expense, ?int $userId = null): ?JournalEntry
    {
        return $this->reverse(
            tenantId: (int) $expense->tenant_id,
            source: $expense,
            event: 'expense',
            entryDate: now()->toDateString(),
            userId: $userId
        );
    }

    public function postGoodsReceipt(GoodsReceipt $receipt, ?int $userId = null): JournalEntry
    {
        $receipt->loadMissing('items.purchaseOrderItem');

        $amount = $this->money((float) $receipt->items->sum(function ($item) {
            $unitCost = $item->purchaseOrderItem?->unit_cost;

            return ((float) $unitCost) * (int) $item->quantity;
        }));

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'goods_receipt' => ['Goods receipt amount must be greater than zero to post accounting entry.'],
            ]);
        }

        return $this->post(
            tenantId: (int) $receipt->tenant_id,
            branchId: $receipt->branch_id ? (int) $receipt->branch_id : null,
            event: 'goods_receipt',
            source: $receipt,
            entryDate: optional($receipt->received_at)->toDateString() ?? now()->toDateString(),
            description: 'Automatic posting for goods receipt '.$receipt->receipt_number,
            lineSpecs: [
                [
                    'account_key' => 'inventory',
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'Inventory received '.$receipt->receipt_number,
                ],
                [
                    'account_key' => 'goods_received_not_billed',
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'Goods received not billed '.$receipt->receipt_number,
                ],
            ],
            userId: $userId
        );
    }

    public function postManualCashTransaction(CashTransaction $cashTransaction, ?int $userId = null): JournalEntry
    {
        [$debitAccountKey, $creditAccountKey, $description] = match ($cashTransaction->transaction_type) {
            CashTransaction::TYPE_OTHER_IN => ['cash_on_hand', 'other_income', 'Manual cash in '.$cashTransaction->voucher_number],
            CashTransaction::TYPE_OTHER_OUT => ['other_expense', 'cash_on_hand', 'Manual cash out '.$cashTransaction->voucher_number],
            CashTransaction::TYPE_PURCHASE_PAYMENT_OUT => ['accounts_payable', 'cash_on_hand', 'Purchase payment '.$cashTransaction->voucher_number],
            CashTransaction::TYPE_MANUAL_ADJUSTMENT => $cashTransaction->direction === 'in'
                ? ['cash_on_hand', 'cash_over_short', 'Cash adjustment in '.$cashTransaction->voucher_number]
                : ['cash_over_short', 'cash_on_hand', 'Cash adjustment out '.$cashTransaction->voucher_number],
            default => throw ValidationException::withMessages([
                'transaction_type' => ['Unsupported cash transaction type for automatic posting.'],
            ]),
        };

        return $this->post(
            tenantId: (int) $cashTransaction->tenant_id,
            branchId: $cashTransaction->branch_id ? (int) $cashTransaction->branch_id : null,
            event: 'manual_cash_transaction',
            source: $cashTransaction,
            entryDate: optional($cashTransaction->transaction_date)->toDateString() ?? now()->toDateString(),
            description: 'Automatic posting for cash voucher '.$cashTransaction->voucher_number,
            lineSpecs: [
                [
                    'account_key' => $debitAccountKey,
                    'debit' => (float) $cashTransaction->amount,
                    'credit' => 0,
                    'description' => $description,
                ],
                [
                    'account_key' => $creditAccountKey,
                    'debit' => 0,
                    'credit' => (float) $cashTransaction->amount,
                    'description' => $description,
                ],
            ],
            userId: $userId
        );
    }

    /**
     * @param  array<int, array{account_key: string, debit: float|int, credit: float|int, description?: string|null}>  $lineSpecs
     */
    private function post(
        int $tenantId,
        ?int $branchId,
        string $event,
        Model $source,
        string $entryDate,
        string $description,
        array $lineSpecs,
        ?int $userId = null
    ): JournalEntry {
        return DB::transaction(function () use ($tenantId, $branchId, $event, $source, $entryDate, $description, $lineSpecs, $userId) {
            $existingPosting = DocumentPosting::query()
                ->where('tenant_id', $tenantId)
                ->where('source_type', $source::class)
                ->where('source_id', (int) $source->getKey())
                ->where('event', $event)
                ->first();

            if ($existingPosting !== null) {
                return $existingPosting->journalEntry()->with('lines.account')->firstOrFail();
            }

            $preparedLines = [];
            $totalDebit = 0.0;
            $totalCredit = 0.0;
            $order = 1;

            foreach ($lineSpecs as $spec) {
                $debit = $this->money((float) ($spec['debit'] ?? 0));
                $credit = $this->money((float) ($spec['credit'] ?? 0));

                if ($debit <= 0 && $credit <= 0) {
                    continue;
                }

                $account = $this->accounts->getBySystemKey($tenantId, $spec['account_key']);
                $preparedLines[] = [
                    'account_id' => $account->id,
                    'description' => $spec['description'] ?? $description,
                    'debit' => $debit,
                    'credit' => $credit,
                    'sort_order' => $order++,
                ];
                $totalDebit += $debit;
                $totalCredit += $credit;
            }

            $totalDebit = $this->money($totalDebit);
            $totalCredit = $this->money($totalCredit);

            if ($preparedLines === [] || $totalDebit <= 0 || abs($totalDebit - $totalCredit) > 0.01) {
                throw ValidationException::withMessages([
                    'accounting' => ['Unable to post an unbalanced journal entry for the document.'],
                ]);
            }

            $entry = JournalEntry::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'entry_number' => $this->generateEntryNumber($tenantId),
                'entry_date' => $entryDate,
                'event' => $event,
                'description' => $description,
                'source_type' => $source::class,
                'source_id' => (int) $source->getKey(),
                'status' => 'posted',
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $entry->lines()->createMany($preparedLines);

            DocumentPosting::create([
                'tenant_id' => $tenantId,
                'source_type' => $source::class,
                'source_id' => (int) $source->getKey(),
                'event' => $event,
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
            ]);

            return $entry->load('lines.account');
        });
    }

    private function reverse(
        int $tenantId,
        Model $source,
        string $event,
        string $entryDate,
        ?int $userId = null
    ): ?JournalEntry {
        return DB::transaction(function () use ($tenantId, $source, $event, $entryDate, $userId) {
            $posting = DocumentPosting::query()
                ->where('tenant_id', $tenantId)
                ->where('source_type', $source::class)
                ->where('source_id', (int) $source->getKey())
                ->where('event', $event)
                ->first();

            if ($posting === null) {
                return null;
            }

            if ($posting->reversal_entry_id !== null) {
                return $posting->reversalEntry()->with('lines.account')->first();
            }

            $originalEntry = $posting->journalEntry()->with('lines')->firstOrFail();

            $reversal = JournalEntry::create([
                'tenant_id' => $tenantId,
                'branch_id' => $originalEntry->branch_id,
                'entry_number' => $this->generateEntryNumber($tenantId),
                'entry_date' => $entryDate,
                'event' => $event.'_reversal',
                'description' => 'Reversal of '.$originalEntry->entry_number.' - '.$originalEntry->description,
                'source_type' => $source::class,
                'source_id' => (int) $source->getKey(),
                'status' => 'posted',
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $reversal->lines()->createMany(
                $originalEntry->lines->map(fn ($line, $index) => [
                    'account_id' => $line->account_id,
                    'description' => $line->description ?: 'Reversal',
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'sort_order' => $index + 1,
                ])->all()
            );

            $originalEntry->update([
                'status' => 'reversed',
                'reversed_entry_id' => $reversal->id,
            ]);

            $posting->update([
                'reversal_entry_id' => $reversal->id,
                'reversed_at' => now(),
            ]);

            return $reversal->load('lines.account');
        });
    }

    private function postPayment(
        Payment $payment,
        string $event,
        string $receivableAccountKey,
        string $referenceNumber,
        string $description,
        ?int $userId = null
    ): JournalEntry {
        $assetAccount = $this->accounts->paymentAssetAccount(
            (int) $payment->tenant_id,
            (string) ($payment->payment_method ?? 'cash')
        );

        return $this->post(
            tenantId: (int) $payment->tenant_id,
            branchId: $payment->branch_id ? (int) $payment->branch_id : null,
            event: $event,
            source: $payment,
            entryDate: optional($payment->payment_date)->toDateString() ?? now()->toDateString(),
            description: $description,
            lineSpecs: [
                [
                    'account_key' => $assetAccount->system_key,
                    'debit' => (float) $payment->amount,
                    'credit' => 0,
                    'description' => 'Receipt '.$referenceNumber,
                ],
                [
                    'account_key' => $receivableAccountKey,
                    'debit' => 0,
                    'credit' => (float) $payment->amount,
                    'description' => 'Settlement '.$referenceNumber,
                ],
            ],
            userId: $userId
        );
    }

    private function costAmountFromOrder($order): float
    {
        if ($order === null) {
            return 0.0;
        }

        $order->loadMissing('items.product');

        return $this->money((float) $order->items->sum(function ($item) {
            $cost = $item->product?->cost_price ?? 0;

            return ((float) $cost) * (int) $item->quantity;
        }));
    }

    private function generateEntryNumber(int $tenantId): string
    {
        $prefix = 'JE-'.str_pad((string) $tenantId, 3, '0', STR_PAD_LEFT).'-';
        $last = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('entry_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('entry_number');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }
}
