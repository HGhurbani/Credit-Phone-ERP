<?php

namespace App\Services;

use App\Models\CollectionLog;
use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Payment;
use App\Models\Receipt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    private const MONEY_SCALE = 2;

    public function __construct(
        private readonly DocumentPostingService $documentPostingService,
    ) {}

    public function record(InstallmentContract $contract, array $data, int $userId): Payment
    {
        return DB::transaction(function () use ($contract, $data, $userId) {
            InstallmentContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();

            $amount = $this->money((float) $data['amount']);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['Payment amount must be greater than zero.']]);
            }

            $scheduleId = array_key_exists('schedule_id', $data) && $data['schedule_id'] !== null && $data['schedule_id'] !== ''
                ? (int) $data['schedule_id']
                : null;

            $schedules = InstallmentSchedule::where('contract_id', $contract->id)
                ->orderBy('due_date')
                ->orderBy('installment_number')
                ->lockForUpdate()
                ->get();

            $this->assertScheduleContractIntegrity($contract, $schedules);

            $maxPayable = $this->money($schedules->sum(fn ($s) => (float) $s->remaining_amount));
            if ($amount > $maxPayable) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Payment exceeds the remaining balance for this contract. Maximum payable: '.$this->formatMoney($maxPayable).'.',
                    ],
                ]);
            }

            if ($scheduleId !== null) {
                $schedule = $schedules->firstWhere('id', $scheduleId);
                if ($schedule === null) {
                    throw ValidationException::withMessages([
                        'schedule_id' => ['The selected installment does not belong to this contract.'],
                    ]);
                }
                $this->assertScheduleMatchesContract($contract, $schedule);

                $lineRemaining = $this->money((float) $schedule->remaining_amount);
                if ($amount > $lineRemaining) {
                    throw ValidationException::withMessages([
                        'amount' => [
                            'Payment exceeds the remaining amount for this installment. Maximum for this line: '.$this->formatMoney($lineRemaining).'.',
                        ],
                    ]);
                }

                $this->applyAmountToScheduleRow($schedule, $amount);
            } else {
                $this->applyAmountAcrossSchedules($schedules, $amount);
            }

            $contractService = app(ContractService::class);
            $contractService->recomputeAllScheduleStatuses($contract->id);
            $contract->refresh();
            $contractService->reconcileContractTotalsFromSchedules($contract);
            $contractService->refreshContractHeaderStatus($contract);

            $payment = Payment::create([
                'tenant_id' => $contract->tenant_id,
                'branch_id' => $contract->branch_id,
                'customer_id' => $contract->customer_id,
                'contract_id' => $contract->id,
                'schedule_id' => $scheduleId,
                'collected_by' => $userId,
                'receipt_number' => $this->generateReceiptNumber($contract->tenant_id),
                'amount' => $amount,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'payment_date' => $data['payment_date'] ?? today(),
                'reference_number' => $data['reference_number'] ?? null,
                'collector_notes' => $data['collector_notes'] ?? null,
            ]);

            Receipt::create([
                'payment_id' => $payment->id,
                'tenant_id' => $contract->tenant_id,
                'receipt_number' => $payment->receipt_number,
            ]);

            CollectionLog::create([
                'tenant_id' => $contract->tenant_id,
                'contract_id' => $contract->id,
                'customer_id' => $contract->customer_id,
                'payment_id' => $payment->id,
                'logged_by' => $userId,
                'action' => 'payment_received',
                'notes' => 'Payment of '.$this->formatMoney($amount).' recorded via '.($data['payment_method'] ?? 'cash'),
            ]);

            $payment = $payment->fresh(['contract', 'customer', 'collectedBy', 'receipt']);

            if (! empty($data['cashbox_id'])) {
                app(CashboxService::class)->tryRecordCashInForPayment(
                    (int) $data['cashbox_id'],
                    $payment,
                    $userId
                );
            }

            $this->documentPostingService->postContractPayment($payment, $userId);

            return $payment;
        });
    }

    /**
     * Sanity check: schedule rows belong to contract tenant (detect drift / bad data).
     */
    private function assertScheduleContractIntegrity(InstallmentContract $contract, $schedules): void
    {
        foreach ($schedules as $s) {
            if ((int) $s->contract_id !== (int) $contract->id) {
                throw ValidationException::withMessages([
                    'contract_id' => ['Contract schedules are inconsistent. Contact support.'],
                ]);
            }
            if ((int) $s->tenant_id !== (int) $contract->tenant_id) {
                throw ValidationException::withMessages([
                    'contract_id' => ['Contract schedules are inconsistent. Contact support.'],
                ]);
            }
        }
    }

    private function assertScheduleMatchesContract(InstallmentContract $contract, InstallmentSchedule $schedule): void
    {
        if ((int) $schedule->contract_id !== (int) $contract->id) {
            throw ValidationException::withMessages([
                'schedule_id' => ['The selected installment does not belong to this contract.'],
            ]);
        }
        if ((int) $schedule->tenant_id !== (int) $contract->tenant_id) {
            throw ValidationException::withMessages([
                'schedule_id' => ['The selected installment is not valid for this contract.'],
            ]);
        }
    }

    private function applyAmountToScheduleRow(InstallmentSchedule $schedule, float $amount): void
    {
        $amount = $this->money($amount);
        $lineAmount = $this->money((float) $schedule->amount);
        $paid = $this->money((float) $schedule->paid_amount + $amount);
        $remaining = $this->money(max(0, $lineAmount - $paid));

        $schedule->update([
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, InstallmentSchedule>  $schedules
     */
    private function applyAmountAcrossSchedules($schedules, float $totalAmount): void
    {
        $remaining = $this->money($totalAmount);

        foreach ($schedules as $schedule) {
            if ($remaining <= 0) {
                break;
            }
            $lineRem = $this->money((float) $schedule->remaining_amount);
            if ($lineRem <= 0) {
                continue;
            }
            $apply = $this->money(min($remaining, $lineRem));
            $this->applyAmountToScheduleRow($schedule, $apply);
            $remaining = $this->money($remaining - $apply);
        }

        if ($remaining > 0.0001) {
            throw ValidationException::withMessages([
                'amount' => ['Unable to allocate payment across installments. Please try again or contact support.'],
            ]);
        }
    }

    private function generateReceiptNumber(int $tenantId): string
    {
        $prefix = 'REC-'.str_pad((string) $tenantId, 3, '0', STR_PAD_LEFT).'-';
        $last = Payment::where('tenant_id', $tenantId)
            ->where('receipt_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('receipt_number');

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
