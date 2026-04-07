<?php

namespace App\Services;

use App\Models\Cashbox;
use App\Models\Expense;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(
        private readonly CashboxService $cashboxService,
    ) {}

    public function generateExpenseNumber(int $tenantId): string
    {
        $prefix = 'EXP-'.str_pad((string) $tenantId, 3, '0', STR_PAD_LEFT).'-';
        $last = Expense::where('tenant_id', $tenantId)
            ->where('expense_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('expense_number');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $tenantId, int $userId): Expense
    {
        return DB::transaction(function () use ($data, $tenantId, $userId) {
            $expense = Expense::create([
                'tenant_id' => $tenantId,
                'branch_id' => $data['branch_id'],
                'cashbox_id' => $data['cashbox_id'] ?? null,
                'expense_number' => $this->generateExpenseNumber($tenantId),
                'category' => $data['category'],
                'amount' => $data['amount'],
                'expense_date' => $data['expense_date'],
                'vendor_name' => $data['vendor_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'active',
                'created_by' => $userId,
            ]);

            if ($expense->cashbox_id !== null) {
                $cashbox = Cashbox::whereKey($expense->cashbox_id)->firstOrFail();
                if ((int) $cashbox->branch_id !== (int) $expense->branch_id) {
                    throw ValidationException::withMessages([
                        'cashbox_id' => ['Cashbox must belong to the selected branch.'],
                    ]);
                }
                $this->cashboxService->assertCashboxMatchesBranchTenant(
                    $cashbox,
                    (int) $expense->branch_id,
                    $tenantId
                );
                $this->cashboxService->recordExpenseOut($expense, $cashbox, $userId);
            }

            return $expense->load(['branch', 'cashbox', 'createdBy']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMetadata(Expense $expense, array $data): Expense
    {
        if ($expense->status !== 'active') {
            throw ValidationException::withMessages([
                'expense' => ['Only active expenses can be updated.'],
            ]);
        }

        $fill = [];
        foreach (['category', 'vendor_name', 'notes', 'expense_date'] as $key) {
            if (array_key_exists($key, $data)) {
                $fill[$key] = $data[$key];
            }
        }
        $expense->update($fill);

        return $expense->fresh(['branch', 'cashbox', 'createdBy']);
    }

    public function cancel(Expense $expense, int $userId): Expense
    {
        if ($expense->status !== 'active') {
            throw ValidationException::withMessages([
                'expense' => ['Expense is already cancelled.'],
            ]);
        }

        return DB::transaction(function () use ($expense, $userId) {
            if ($expense->cashbox_id !== null) {
                $cashbox = Cashbox::whereKey($expense->cashbox_id)->lockForUpdate()->firstOrFail();
                $this->cashboxService->recordExpenseReversalIn($expense, $cashbox, $userId);
            }

            $expense->update(['status' => 'cancelled']);

            return $expense->fresh(['branch', 'cashbox', 'createdBy']);
        });
    }

    public function deleteIfAllowed(Expense $expense): void
    {
        if ($expense->status !== 'active') {
            throw ValidationException::withMessages([
                'expense' => ['Only active expenses can be deleted.'],
            ]);
        }
        if ($expense->cashbox_id !== null) {
            throw ValidationException::withMessages([
                'expense' => ['Cannot delete an expense linked to cash. Cancel it instead.'],
            ]);
        }

        $expense->delete();
    }
}
