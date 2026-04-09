<?php

namespace App\Services;

use App\Models\Cashbox;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashboxService
{
    private const MONEY_SCALE = 2;

    public function __construct(
        private readonly DocumentPostingService $documentPostingService,
    ) {}

    /**
     * Optional cashbox tracking for cash customer/invoice payments.
     */
    public function tryRecordCashInForPayment(?int $cashboxId, Payment $payment, int $userId): ?CashTransaction
    {
        if ($cashboxId === null) {
            return null;
        }

        if (($payment->payment_method ?? '') !== 'cash') {
            throw ValidationException::withMessages([
                'cashbox_id' => ['Cashbox tracking is only applicable for cash payments.'],
            ]);
        }

        return DB::transaction(function () use ($cashboxId, $payment, $userId) {
            $cashbox = Cashbox::whereKey($cashboxId)->lockForUpdate()->firstOrFail();
            $this->assertCashboxMatchesBranchTenant($cashbox, (int) $payment->branch_id, (int) $payment->tenant_id);

            return $this->pushTransaction(
                $cashbox,
                CashTransaction::TYPE_CUSTOMER_PAYMENT_IN,
                'in',
                (float) $payment->amount,
                $payment->payment_date,
                'Customer payment — '.$payment->receipt_number,
                $userId,
                Payment::class,
                $payment->id
            );
        });
    }

    public function recordExpenseOut(Expense $expense, Cashbox $cashbox, int $userId): CashTransaction
    {
        return DB::transaction(function () use ($expense, $cashbox, $userId) {
            $locked = Cashbox::whereKey($cashbox->id)->lockForUpdate()->firstOrFail();
            $this->assertCashboxMatchesBranchTenant($locked, (int) $expense->branch_id, (int) $expense->tenant_id);

            return $this->pushTransaction(
                $locked,
                CashTransaction::TYPE_EXPENSE_OUT,
                'out',
                (float) $expense->amount,
                $expense->expense_date,
                'Expense '.$expense->expense_number,
                $userId,
                Expense::class,
                $expense->id
            );
        });
    }

    /**
     * Reverse cash effect when an expense is cancelled (money back into cashbox).
     */
    public function recordExpenseReversalIn(Expense $expense, Cashbox $cashbox, int $userId): CashTransaction
    {
        return DB::transaction(function () use ($expense, $cashbox, $userId) {
            $locked = Cashbox::whereKey($cashbox->id)->lockForUpdate()->firstOrFail();
            $this->assertCashboxMatchesBranchTenant($locked, (int) $expense->branch_id, (int) $expense->tenant_id);

            return $this->pushTransaction(
                $locked,
                CashTransaction::TYPE_OTHER_IN,
                'in',
                (float) $expense->amount,
                now()->toDateString(),
                'Reversal — cancelled expense '.$expense->expense_number,
                $userId,
                Expense::class,
                $expense->id
            );
        });
    }

    /**
     * Manual branch cash movement (in/out/other).
     *
     * @param  array{transaction_type: string, amount: float|string, transaction_date: string, notes?: string|null, reference_type?: string|null, reference_id?: int|null}  $data
     */
    public function recordManual(Cashbox $cashbox, array $data, int $userId): CashTransaction
    {
        $type = $data['transaction_type'];
        $amount = $this->money((float) $data['amount']);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
        }

        $direction = $this->directionForManualType($type);
        if ($direction === null) {
            throw ValidationException::withMessages(['transaction_type' => ['Invalid transaction type.']]);
        }

        return DB::transaction(function () use ($cashbox, $data, $amount, $direction, $type, $userId) {
            $locked = Cashbox::whereKey($cashbox->id)->lockForUpdate()->firstOrFail();

            $transaction = $this->pushTransaction(
                $locked,
                $type,
                $direction,
                $amount,
                $data['transaction_date'],
                $data['notes'] ?? null,
                $userId,
                $data['reference_type'] ?? null,
                isset($data['reference_id']) ? (int) $data['reference_id'] : null
            );

            $this->documentPostingService->postManualCashTransaction($transaction, $userId);

            return $transaction;
        });
    }

    private function directionForManualType(string $type): ?string
    {
        return match ($type) {
            CashTransaction::TYPE_OTHER_IN => 'in',
            CashTransaction::TYPE_OTHER_OUT,
            CashTransaction::TYPE_PURCHASE_PAYMENT_OUT => 'out',
            CashTransaction::TYPE_MANUAL_ADJUSTMENT => null,
            default => null,
        };
    }

    /**
     * manual_adjustment uses explicit direction in $data.
     *
     * @param  array{direction: 'in'|'out', amount: float|string, transaction_date: string, notes?: string|null}  $data
     */
    public function recordManualAdjustment(Cashbox $cashbox, array $data, int $userId): CashTransaction
    {
        $direction = $data['direction'];
        if (! in_array($direction, ['in', 'out'], true)) {
            throw ValidationException::withMessages(['direction' => ['Invalid direction.']]);
        }

        $amount = $this->money((float) $data['amount']);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
        }

        return DB::transaction(function () use ($cashbox, $data, $amount, $direction, $userId) {
            $locked = Cashbox::whereKey($cashbox->id)->lockForUpdate()->firstOrFail();

            $transaction = $this->pushTransaction(
                $locked,
                CashTransaction::TYPE_MANUAL_ADJUSTMENT,
                $direction,
                $amount,
                $data['transaction_date'],
                $data['notes'] ?? null,
                $userId,
                null,
                null
            );

            $this->documentPostingService->postManualCashTransaction($transaction, $userId);

            return $transaction;
        });
    }

    private function pushTransaction(
        Cashbox $cashbox,
        string $type,
        string $direction,
        float $amount,
        $transactionDate,
        ?string $notes,
        int $userId,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): CashTransaction {
        $amount = $this->money($amount);

        if ($direction === 'in') {
            $cashbox->increment('current_balance', $amount);
        } else {
            $before = $this->money((float) $cashbox->current_balance);
            if ($before < $amount) {
                throw ValidationException::withMessages([
                    'amount' => ['Insufficient cash in cashbox. Available: '.$this->formatMoney($before).'.'],
                ]);
            }
            $cashbox->decrement('current_balance', $amount);
        }

        $cashbox->refresh();

        $voucherNumber = $this->generateVoucherNumber((int) $cashbox->tenant_id, $direction);

        $dateStr = $transactionDate instanceof \DateTimeInterface
            ? $transactionDate->format('Y-m-d')
            : (string) $transactionDate;

        return CashTransaction::create([
            'tenant_id' => $cashbox->tenant_id,
            'cashbox_id' => $cashbox->id,
            'branch_id' => $cashbox->branch_id,
            'transaction_type' => $type,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'amount' => $amount,
            'direction' => $direction,
            'transaction_date' => $dateStr,
            'notes' => $notes,
            'created_by' => $userId,
            'voucher_number' => $voucherNumber,
        ]);
    }

    public function assertCashboxMatchesBranchTenant(Cashbox $cashbox, int $branchId, int $tenantId): void
    {
        if ((int) $cashbox->branch_id !== $branchId || (int) $cashbox->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'cashbox_id' => ['Cashbox does not belong to this branch.'],
            ]);
        }
        if (! $cashbox->is_active) {
            throw ValidationException::withMessages(['cashbox_id' => ['Cashbox is inactive.']]);
        }
    }

    private function generateVoucherNumber(int $tenantId, string $direction): string
    {
        $prefix = $direction === 'in'
            ? 'CR-'.str_pad((string) $tenantId, 3, '0', STR_PAD_LEFT).'-'
            : 'CP-'.str_pad((string) $tenantId, 3, '0', STR_PAD_LEFT).'-';

        $last = CashTransaction::where('tenant_id', $tenantId)
            ->where('voucher_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('voucher_number');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function money(float $value): float
    {
        return round($value, self::MONEY_SCALE);
    }

    private function formatMoney(float $value): string
    {
        return number_format($this->money($value), self::MONEY_SCALE, '.', '');
    }
}
