<?php

namespace Tests\Feature\Payments;

use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    private function createContractViaApi(): array
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();
        $admin = User::factory()->forTenant($ctx['tenant']->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/contracts', $this->validContractPayloadForOrder($ctx['order']))
            ->assertCreated();

        $contract = InstallmentContract::where('order_id', $ctx['order']->id)->firstOrFail();

        return array_merge($ctx, ['contract' => $contract, 'admin' => $admin]);
    }

    private function paymentPayload(int $contractId, float $amount, ?int $scheduleId = null): array
    {
        return [
            'contract_id' => $contractId,
            'schedule_id' => $scheduleId,
            'amount' => $amount,
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
        ];
    }

    public function test_targeted_payment_to_schedule_succeeds(): void
    {
        $ctx = $this->createContractViaApi();
        $contract = $ctx['contract']->fresh();
        $schedule = InstallmentSchedule::where('contract_id', $contract->id)->orderBy('installment_number')->firstOrFail();

        Sanctum::actingAs($ctx['admin']);

        $response = $this->postJson('/api/payments', $this->paymentPayload($contract->id, 50.0, $schedule->id));

        $response->assertCreated();
        $schedule->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $schedule->paid_amount, 0.01);
    }

    public function test_untargeted_payment_allocates_across_schedules(): void
    {
        $ctx = $this->createContractViaApi();
        $contract = $ctx['contract']->fresh();

        Sanctum::actingAs($ctx['admin']);

        $response = $this->postJson('/api/payments', $this->paymentPayload($contract->id, 75.0, null));

        $response->assertCreated();
        $contract->refresh();
        $this->assertEqualsWithDelta(75.0, (float) $contract->paid_amount, 0.02);
    }

    public function test_wrong_schedule_id_for_contract_returns_422(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();
        $ctx2 = $this->createApprovedInstallmentOrderForTenantBranch($ctx['tenant'], $ctx['branch']);

        $admin = User::factory()->forTenant($ctx['tenant']->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/contracts', $this->validContractPayloadForOrder($ctx['order']))->assertCreated();
        $this->postJson('/api/contracts', $this->validContractPayloadForOrder($ctx2['order']))->assertCreated();

        $contractA = InstallmentContract::where('order_id', $ctx['order']->id)->firstOrFail();
        $contractB = InstallmentContract::where('order_id', $ctx2['order']->id)->firstOrFail();
        $scheduleB = InstallmentSchedule::where('contract_id', $contractB->id)->firstOrFail();

        Sanctum::actingAs($admin);

        $this->postJson('/api/payments', $this->paymentPayload($contractA->id, 10.0, $scheduleB->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['schedule_id']);
    }

    public function test_payment_exceeding_schedule_remaining_returns_422(): void
    {
        $ctx = $this->createContractViaApi();
        $contract = $ctx['contract']->fresh();
        $schedule = InstallmentSchedule::where('contract_id', $contract->id)->orderBy('installment_number')->firstOrFail();
        $lineMax = (float) $schedule->remaining_amount;

        Sanctum::actingAs($ctx['admin']);

        $this->postJson('/api/payments', $this->paymentPayload($contract->id, $lineMax + 1, $schedule->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_payment_exceeding_contract_remaining_returns_422(): void
    {
        $ctx = $this->createContractViaApi();
        $contract = $ctx['contract']->fresh();
        $maxPayable = (float) InstallmentSchedule::where('contract_id', $contract->id)->sum('remaining_amount');

        Sanctum::actingAs($ctx['admin']);

        $this->postJson('/api/payments', $this->paymentPayload($contract->id, $maxPayable + 0.5, null))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_successful_payment_reconciles_contract_totals_from_schedules(): void
    {
        $ctx = $this->createContractViaApi();
        $contract = $ctx['contract']->fresh();
        $amount = 100.0;

        Sanctum::actingAs($ctx['admin']);

        $this->postJson('/api/payments', $this->paymentPayload($contract->id, $amount, null))->assertCreated();

        $contract->refresh();
        $sumPaid = round((float) InstallmentSchedule::where('contract_id', $contract->id)->sum('paid_amount'), 2);
        $sumRem = round((float) InstallmentSchedule::where('contract_id', $contract->id)->sum('remaining_amount'), 2);

        $this->assertEqualsWithDelta($sumPaid, (float) $contract->paid_amount, 0.02);
        $this->assertEqualsWithDelta($sumRem, (float) $contract->remaining_amount, 0.02);
    }
}
