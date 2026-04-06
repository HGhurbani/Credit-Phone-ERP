<?php

namespace App\Services;

use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\TenantBranchScope;
use App\Support\TenantSettings;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractService
{
    public function createFromOrder(Order $order, array $data, User $user): InstallmentContract
    {
        return DB::transaction(function () use ($order, $data, $user) {
            $userId = $user->id;

            $order = Order::whereKey($order->id)
                ->lockForUpdate()
                ->with(['items.product', 'contract'])
                ->firstOrFail();

            $this->assertOrderEligibleForContract($order, $user);

            if ($order->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'order_id' => ['The order has no line items.'],
                ]);
            }

            foreach ($order->items as $item) {
                if (! $item->product) {
                    throw ValidationException::withMessages([
                        'order_id' => ['A line item references a product that no longer exists.'],
                    ]);
                }
                if ((int) $item->product->tenant_id !== (int) $order->tenant_id) {
                    throw ValidationException::withMessages([
                        'order_id' => ['A product on this order does not belong to this tenant.'],
                    ]);
                }
            }

            $startDate = Carbon::parse($data['start_date']);
            $firstDueDate = Carbon::parse($data['first_due_date']);
            $durationMonths = (int) $data['duration_months'];
            $downPayment = (float) $data['down_payment'];

            $pricing = $this->computeInstallmentPricing($order, $durationMonths, $downPayment);

            $monthlyAmount = $pricing['monthly_amount'];
            $financedAmount = $pricing['financed_amount'];
            $totalAmount = $financedAmount;
            $endDate = $firstDueDate->copy()->addMonths($durationMonths - 1);

            $this->validateInventoryStockForOrder($order);

            $contract = InstallmentContract::create([
                'tenant_id' => $order->tenant_id,
                'branch_id' => $order->branch_id,
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'created_by' => $userId,
                'contract_number' => $this->generateContractNumber($order->tenant_id),
                'financed_amount' => $financedAmount,
                'down_payment' => $downPayment,
                'duration_months' => $durationMonths,
                'monthly_amount' => $monthlyAmount,
                'total_amount' => $totalAmount,
                'paid_amount' => 0,
                'remaining_amount' => $totalAmount,
                'start_date' => $startDate,
                'first_due_date' => $firstDueDate,
                'end_date' => $endDate,
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->generateSchedule($contract);

            $this->deductStockForOrder($order, $userId);

            $order->update(['status' => 'converted_to_contract']);

            return $contract->load(['customer', 'order', 'schedules']);
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function assertOrderEligibleForContract(Order $order, User $user): void
    {
        if (! $user->isSuperAdmin() && (int) $order->tenant_id !== (int) $user->tenant_id) {
            throw new AuthorizationException('Access denied.');
        }

        if (TenantBranchScope::isBranchScoped($user)
            && (int) $order->branch_id !== (int) $user->branch_id) {
            abort(403, 'Access denied.');
        }

        if (! $order->canBeConverted()) {
            throw ValidationException::withMessages([
                'order_id' => ['This order cannot be converted to a contract (must be approved and installment).'],
            ]);
        }

        if ($order->contract()->exists()) {
            throw ValidationException::withMessages([
                'order_id' => ['This order already has a contract.'],
            ]);
        }
    }

    /**
     * @return array{monthly_amount: float, financed_amount: float}
     */
    private function computeInstallmentPricing(Order $order, int $durationMonths, float $downPayment): array
    {
        $mode = TenantSettings::string($order->tenant_id, 'installment_pricing_mode', 'percentage');

        $cashTotal = (float) $order->items->sum(fn ($i) => (float) $i->product->cash_price * $i->quantity);

        if ($mode === 'percentage') {
            $defaultPercent = TenantSettings::float($order->tenant_id, 'installment_monthly_percent_of_cash', 5.0);
            $weighted = 0.0;
            foreach ($order->items as $item) {
                $lineCash = (float) $item->product->cash_price * $item->quantity;
                $p = $item->product->monthly_percent_of_cash !== null
                    ? (float) $item->product->monthly_percent_of_cash
                    : $defaultPercent;
                $weighted += $lineCash * $p;
            }
            $effectivePercent = $cashTotal > 0 ? $weighted / $cashTotal : $defaultPercent;
            $monthlyAmount = round($cashTotal * ($effectivePercent / 100), 2);
        } else {
            $sum = 0.0;
            foreach ($order->items as $item) {
                $fm = $item->product->fixed_monthly_amount;
                if ($fm === null) {
                    throw ValidationException::withMessages([
                        'order_id' => ['Product "'.$item->product->name.'" is missing fixed monthly amount (fixed installment mode).'],
                    ]);
                }
                $sum += (float) $fm * $item->quantity;
            }
            $monthlyAmount = round($sum, 2);
        }

        if ($monthlyAmount <= 0) {
            throw ValidationException::withMessages([
                'order_id' => ['Calculated monthly installment must be greater than zero.'],
            ]);
        }

        $financedAmount = round($monthlyAmount * $durationMonths, 2);
        $orderTotal = (float) $order->total;

        if ($orderTotal + 0.01 < $financedAmount) {
            throw ValidationException::withMessages([
                'duration_months' => ['Order total is less than the financed amount for the selected duration.'],
            ]);
        }

        $expectedDown = round($orderTotal - $financedAmount, 2);
        if (abs($expectedDown - $downPayment) > 0.05) {
            throw ValidationException::withMessages([
                'down_payment' => [
                    'Down payment must be '.number_format($expectedDown, 2).' to match the order total and the installment schedule (financed: '.number_format($financedAmount, 2).'). You entered: '.number_format($downPayment, 2).'.',
                ],
            ]);
        }

        return [
            'monthly_amount' => $monthlyAmount,
            'financed_amount' => $financedAmount,
        ];
    }

    /**
     * Lock inventory per product (sorted product id) and ensure total quantity across all lines is covered.
     */
    private function validateInventoryStockForOrder(Order $order): void
    {
        $grouped = $order->items->groupBy('product_id');

        foreach ($grouped->sortKeys() as $productId => $items) {
            $qtyNeeded = (int) $items->sum('quantity');

            $inventory = Inventory::where('product_id', $productId)
                ->where('branch_id', $order->branch_id)
                ->lockForUpdate()
                ->first();

            $first = $items->first();
            $label = $first->product_name ?: ($first->product->name ?? 'Product #'.$productId);

            if ($inventory === null) {
                throw ValidationException::withMessages([
                    'order_id' => ['No inventory record for "'.$label.'" at this branch. Initialize stock before converting this order.'],
                ]);
            }

            $available = (int) $inventory->quantity;

            if ($available < $qtyNeeded) {
                throw ValidationException::withMessages([
                    'order_id' => ['Insufficient stock for "'.$label.'". Required: '.$qtyNeeded.', available: '.$available.'.'],
                ]);
            }
        }
    }

    /**
     * One movement per order line; same product on multiple lines uses sequential decrements on the same inventory row.
     */
    private function deductStockForOrder(Order $order, int $userId): void
    {
        foreach ($order->items->sortBy('id') as $item) {
            $inventory = Inventory::where('product_id', $item->product_id)
                ->where('branch_id', $order->branch_id)
                ->lockForUpdate()
                ->first();

            if ($inventory === null) {
                throw ValidationException::withMessages([
                    'order_id' => ['Inventory row missing during stock deduction. The operation was rolled back.'],
                ]);
            }

            $before = (int) $inventory->quantity;
            $qty = (int) $item->quantity;

            if ($before < $qty) {
                throw ValidationException::withMessages([
                    'order_id' => ['Insufficient stock at deduction time for "'.$item->product_name.'". The operation was rolled back.'],
                ]);
            }

            $inventory->decrement('quantity', $qty);
            $after = $before - $qty;

            StockMovement::create([
                'product_id' => $item->product_id,
                'branch_id' => $order->branch_id,
                'created_by' => $userId,
                'type' => 'out',
                'quantity' => $qty,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'serial_number' => $item->serial_number,
            ]);
        }
    }

    public function generateSchedule(InstallmentContract $contract): void
    {
        $schedules = [];
        $dueDate = Carbon::parse($contract->first_due_date);

        for ($i = 1; $i <= $contract->duration_months; $i++) {
            $schedules[] = [
                'contract_id' => $contract->id,
                'tenant_id' => $contract->tenant_id,
                'installment_number' => $i,
                'due_date' => $dueDate->copy(),
                'amount' => $contract->monthly_amount,
                'paid_amount' => 0,
                'remaining_amount' => $contract->monthly_amount,
                'status' => $dueDate->isToday() ? 'due_today' : ($dueDate->isPast() ? 'overdue' : 'upcoming'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $dueDate->addMonth();
        }

        InstallmentSchedule::insert($schedules);
    }

    public function recordPayment(InstallmentContract $contract, array $data, int $userId): array
    {
        $paymentService = app(PaymentService::class);
        $payment = $paymentService->record($contract, $data, $userId);

        return ['contract' => $contract->fresh(['schedules']), 'payment' => $payment];
    }

    /**
     * Recompute schedule row statuses from amounts + due dates (single source of truth).
     */
    public function recomputeAllScheduleStatuses(int $contractId): void
    {
        $schedules = InstallmentSchedule::where('contract_id', $contractId)
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->get();

        foreach ($schedules as $schedule) {
            $this->recomputeSingleScheduleStatus($schedule);
        }
    }

    private function recomputeSingleScheduleStatus(InstallmentSchedule $schedule): void
    {
        $remaining = round((float) $schedule->remaining_amount, 2);
        $due = Carbon::parse($schedule->due_date)->startOfDay();
        $today = today()->startOfDay();
        $paidPortion = round((float) $schedule->paid_amount, 2);

        if ($remaining <= 0.0001) {
            $status = 'paid';
        } elseif ($due->lt($today)) {
            $status = 'overdue';
        } elseif ($due->equalTo($today)) {
            $status = 'due_today';
        } elseif ($paidPortion > 0.0001) {
            $status = 'partial';
        } else {
            $status = 'upcoming';
        }

        $schedule->update([
            'status' => $status,
            'paid_date' => $status === 'paid' ? ($schedule->paid_date ?? today()) : $schedule->paid_date,
        ]);
    }

    /**
     * Set contract paid_amount / remaining_amount from schedule aggregates (authoritative).
     */
    public function reconcileContractTotalsFromSchedules(InstallmentContract $contract): void
    {
        $schedules = InstallmentSchedule::where('contract_id', $contract->id)->get();

        $sumPaid = round($schedules->sum(fn ($s) => (float) $s->paid_amount), 2);
        $sumRemaining = round($schedules->sum(fn ($s) => (float) $s->remaining_amount), 2);

        $contract->update([
            'paid_amount' => $sumPaid,
            'remaining_amount' => max(0, $sumRemaining),
        ]);
    }

    /**
     * Contract header: completed / overdue / active — after schedules + totals are current.
     */
    public function refreshContractHeaderStatus(InstallmentContract $contract): void
    {
        $contract->refresh();

        if ((float) $contract->remaining_amount <= 0.0001) {
            $contract->update(['status' => 'completed']);

            return;
        }

        if (InstallmentSchedule::where('contract_id', $contract->id)->where('status', 'overdue')->exists()) {
            $contract->update(['status' => 'overdue']);

            return;
        }

        $contract->update(['status' => 'active']);
    }

    /**
     * Refresh schedules + contract totals + header (e.g. contract detail / schedules API).
     */
    public function refreshStatus(InstallmentContract $contract): void
    {
        $this->recomputeAllScheduleStatuses($contract->id);
        $contract->refresh();
        $this->reconcileContractTotalsFromSchedules($contract);
        $this->refreshContractHeaderStatus($contract);
    }

    private function generateContractNumber(int $tenantId): string
    {
        $prefix = 'CON-' . str_pad($tenantId, 3, '0', STR_PAD_LEFT) . '-';
        $last = InstallmentContract::where('tenant_id', $tenantId)
            ->where('contract_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('contract_number');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad($next, 6, '0', STR_PAD_LEFT);
    }
}
