<?php

namespace App\Services;

use App\Models\CollectionLog;
use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function record(InstallmentContract $contract, array $data, int $userId): Payment
    {
        return DB::transaction(function () use ($contract, $data, $userId) {
            $scheduleId = $data['schedule_id'] ?? null;

            $payment = Payment::create([
                'tenant_id' => $contract->tenant_id,
                'branch_id' => $contract->branch_id,
                'customer_id' => $contract->customer_id,
                'contract_id' => $contract->id,
                'schedule_id' => $scheduleId,
                'collected_by' => $userId,
                'receipt_number' => $this->generateReceiptNumber($contract->tenant_id),
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'] ?? 'cash',
                'payment_date' => $data['payment_date'] ?? today(),
                'reference_number' => $data['reference_number'] ?? null,
                'collector_notes' => $data['collector_notes'] ?? null,
            ]);

            // Update schedule line if specified
            if ($scheduleId) {
                $this->updateSchedule($scheduleId, $data['amount']);
            } else {
                // Apply to oldest unpaid schedule
                $this->applyToSchedules($contract, $data['amount']);
            }

            // Update contract paid/remaining amounts
            $contract->increment('paid_amount', $data['amount']);
            $contract->decrement('remaining_amount', $data['amount']);

            // Generate receipt record
            Receipt::create([
                'payment_id' => $payment->id,
                'tenant_id' => $contract->tenant_id,
                'receipt_number' => $payment->receipt_number,
            ]);

            // Log collection event
            CollectionLog::create([
                'tenant_id' => $contract->tenant_id,
                'contract_id' => $contract->id,
                'customer_id' => $contract->customer_id,
                'payment_id' => $payment->id,
                'logged_by' => $userId,
                'action' => 'payment_received',
                'notes' => 'Payment of ' . $data['amount'] . ' recorded via ' . ($data['payment_method'] ?? 'cash'),
            ]);

            // Refresh contract status
            app(ContractService::class)->refreshStatus($contract);

            return $payment->load(['contract', 'customer', 'collectedBy', 'receipt']);
        });
    }

    private function updateSchedule(int $scheduleId, float $amount): void
    {
        $schedule = InstallmentSchedule::find($scheduleId);
        if (!$schedule) return;

        $newPaid = $schedule->paid_amount + $amount;
        $newRemaining = max(0, $schedule->amount - $newPaid);
        $status = $newRemaining <= 0 ? 'paid' : 'partial';

        $schedule->update([
            'paid_amount' => $newPaid,
            'remaining_amount' => $newRemaining,
            'status' => $status,
            'paid_date' => $status === 'paid' ? today() : $schedule->paid_date,
        ]);
    }

    private function applyToSchedules(InstallmentContract $contract, float $amount): void
    {
        $remaining = $amount;

        $schedules = InstallmentSchedule::where('contract_id', $contract->id)
            ->whereIn('status', ['overdue', 'due_today', 'partial', 'upcoming'])
            ->orderBy('due_date')
            ->get();

        foreach ($schedules as $schedule) {
            if ($remaining <= 0) break;

            $toApply = min($remaining, $schedule->remaining_amount);
            $remaining -= $toApply;

            $newPaid = $schedule->paid_amount + $toApply;
            $newRemaining = max(0, $schedule->amount - $newPaid);
            $status = $newRemaining <= 0 ? 'paid' : 'partial';

            $schedule->update([
                'paid_amount' => $newPaid,
                'remaining_amount' => $newRemaining,
                'status' => $status,
                'paid_date' => $status === 'paid' ? today() : $schedule->paid_date,
            ]);
        }
    }

    private function generateReceiptNumber(int $tenantId): string
    {
        $prefix = 'REC-' . str_pad($tenantId, 3, '0', STR_PAD_LEFT) . '-';
        $last = Payment::where('tenant_id', $tenantId)
            ->where('receipt_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('receipt_number');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad($next, 6, '0', STR_PAD_LEFT);
    }
}
