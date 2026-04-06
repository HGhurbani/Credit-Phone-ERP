<?php

namespace App\Services;

use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Support\TenantSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ContractService
{
    public function createFromOrder(Order $order, array $data, int $userId): InstallmentContract
    {
        return DB::transaction(function () use ($order, $data, $userId) {
            $order->loadMissing('items.product');

            $startDate = Carbon::parse($data['start_date']);
            $firstDueDate = Carbon::parse($data['first_due_date']);
            $durationMonths = (int) $data['duration_months'];
            $downPayment = (float) $data['down_payment'];

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
                        throw new \InvalidArgumentException('Product "'.$item->product->name.'" is missing fixed monthly amount (fixed installment mode).');
                    }
                    $sum += (float) $fm * $item->quantity;
                }
                $monthlyAmount = round($sum, 2);
            }

            if ($monthlyAmount <= 0) {
                throw new \InvalidArgumentException('Calculated monthly installment must be greater than zero.');
            }

            $financedAmount = round($monthlyAmount * $durationMonths, 2);
            $orderTotal = (float) $order->total;

            if ($orderTotal + 0.01 < $financedAmount) {
                throw new \InvalidArgumentException('Order total is less than the financed amount for the selected duration.');
            }

            $expectedDown = round($orderTotal - $financedAmount, 2);
            if (abs($expectedDown - $downPayment) > 0.05) {
                throw new \InvalidArgumentException(
                    'Down payment must be '.number_format($expectedDown, 2).' to match the order total and the installment schedule (financed: '.number_format($financedAmount, 2).'). You entered: '.number_format($downPayment, 2).'.'
                );
            }

            $totalAmount = $financedAmount;
            $endDate = $firstDueDate->copy()->addMonths($durationMonths - 1);

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

            $this->deductStock($order, $userId);

            $order->update(['status' => 'converted_to_contract']);

            return $contract->load(['customer', 'order', 'schedules']);
        });
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
        return DB::transaction(function () use ($contract, $data, $userId) {
            $paymentService = app(PaymentService::class);
            $payment = $paymentService->record($contract, $data, $userId);

            return ['contract' => $contract->fresh(['schedules']), 'payment' => $payment];
        });
    }

    public function refreshStatus(InstallmentContract $contract): void
    {
        InstallmentSchedule::where('contract_id', $contract->id)
            ->whereIn('status', ['upcoming', 'due_today', 'partial'])
            ->where('due_date', '<', today())
            ->update(['status' => 'overdue']);

        InstallmentSchedule::where('contract_id', $contract->id)
            ->whereIn('status', ['upcoming'])
            ->whereDate('due_date', today())
            ->update(['status' => 'due_today']);

        $contract->refresh();

        if ($contract->remaining_amount <= 0) {
            $contract->update(['status' => 'completed']);
        } elseif (
            InstallmentSchedule::where('contract_id', $contract->id)
                ->where('status', 'overdue')
                ->exists()
        ) {
            $contract->update(['status' => 'overdue']);
        }
    }

    private function deductStock(Order $order, int $userId): void
    {
        foreach ($order->items as $item) {
            $inventory = Inventory::where('product_id', $item->product_id)
                ->where('branch_id', $order->branch_id)
                ->first();

            if ($inventory) {
                $before = $inventory->quantity;
                $inventory->decrement('quantity', $item->quantity);

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'branch_id' => $order->branch_id,
                    'created_by' => $userId,
                    'type' => 'out',
                    'quantity' => $item->quantity,
                    'quantity_before' => $before,
                    'quantity_after' => $before - $item->quantity,
                    'reference_type' => Order::class,
                    'reference_id' => $order->id,
                    'serial_number' => $item->serial_number,
                ]);
            }
        }
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
