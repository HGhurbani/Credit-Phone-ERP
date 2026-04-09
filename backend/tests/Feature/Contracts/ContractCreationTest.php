<?php

namespace Tests\Feature\Contracts;

use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_approved_installment_order_converts_successfully(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();
        $admin = User::factory()->forTenant($ctx['tenant']->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/contracts', $this->validContractPayloadForOrder($ctx['order']));

        $response->assertCreated();
        $ctx['order']->refresh();
        $this->assertSame('converted_to_contract', $ctx['order']->status);
        $this->assertSame(1, InstallmentContract::where('order_id', $ctx['order']->id)->count());
    }

    public function test_second_conversion_attempt_is_rejected(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();
        $admin = User::factory()->forTenant($ctx['tenant']->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $payload = $this->validContractPayloadForOrder($ctx['order']);
        $this->postJson('/api/contracts', $payload)->assertCreated();

        $response = $this->postJson('/api/contracts', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['order_id']);
    }

    public function test_missing_inventory_row_returns_422_and_does_not_create_contract(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();
        $ctx['inventory']->delete();

        $admin = User::factory()->forTenant($ctx['tenant']->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $before = InstallmentContract::count();
        $response = $this->postJson('/api/contracts', $this->validContractPayloadForOrder($ctx['order']));

        $response->assertStatus(422)->assertJsonValidationErrors(['order_id']);
        $this->assertSame($before, InstallmentContract::count());
        $this->assertSame('approved', $ctx['order']->fresh()->status);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_insufficient_stock_returns_422_and_does_not_mutate_data(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();
        $ctx['inventory']->update(['quantity' => 0]);

        $admin = User::factory()->forTenant($ctx['tenant']->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $beforeContracts = InstallmentContract::count();
        $response = $this->postJson('/api/contracts', $this->validContractPayloadForOrder($ctx['order']));

        $response->assertStatus(422)->assertJsonValidationErrors(['order_id']);
        $this->assertSame($beforeContracts, InstallmentContract::count());
        $this->assertSame('approved', $ctx['order']->fresh()->status);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_failed_conversion_does_not_partially_write_contract_schedules_or_stock(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();
        $ctx['inventory']->delete();

        $admin = User::factory()->forTenant($ctx['tenant']->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/contracts', $this->validContractPayloadForOrder($ctx['order']))
            ->assertStatus(422);

        $this->assertDatabaseMissing('installment_contracts', ['order_id' => $ctx['order']->id]);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('approved', Order::find($ctx['order']->id)->status);
    }

    public function test_contract_conversion_enforces_minimum_down_payment_and_rounds_monthly_up(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();
        $ctx['product']->update([
            'installment_price' => 1000,
            'min_down_payment' => 100,
        ]);

        $admin = User::factory()->forTenant($ctx['tenant']->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $payload = [
            'order_id' => $ctx['order']->id,
            'down_payment' => 100,
            'duration_months' => 7,
            'start_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
        ];

        $response = $this->postJson('/api/contracts', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.down_payment', '100.00')
            ->assertJsonPath('data.financed_amount', '900.00')
            ->assertJsonPath('data.monthly_amount', '129.00');

        $contract = InstallmentContract::query()->where('order_id', $ctx['order']->id)->firstOrFail();
        $schedules = InstallmentSchedule::query()
            ->where('contract_id', $contract->id)
            ->orderBy('installment_number')
            ->get();

        $this->assertCount(7, $schedules);
        $this->assertSame(129.0, (float) $schedules[0]->amount);
        $this->assertSame(126.0, (float) $schedules[6]->amount);
        $this->assertEqualsWithDelta(900.0, (float) $schedules->sum('amount'), 0.01);
    }

    public function test_contract_conversion_rejects_down_payment_below_minimum(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();
        $ctx['product']->update([
            'installment_price' => 1000,
            'min_down_payment' => 100,
        ]);

        $admin = User::factory()->forTenant($ctx['tenant']->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $payload = [
            'order_id' => $ctx['order']->id,
            'down_payment' => 99,
            'duration_months' => 7,
            'start_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
        ];

        $this->postJson('/api/contracts', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['down_payment']);
    }

    public function test_contract_conversion_accepts_manual_monthly_amount_and_adjusts_last_installment(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();
        $ctx['product']->update([
            'installment_price' => 1000,
            'min_down_payment' => 100,
        ]);

        $admin = User::factory()->forTenant($ctx['tenant']->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $payload = [
            'order_id' => $ctx['order']->id,
            'down_payment' => 100,
            'monthly_amount' => 120,
            'duration_months' => 7,
            'start_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
        ];

        $response = $this->postJson('/api/contracts', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.monthly_amount', '120.00');

        $contract = InstallmentContract::query()->where('order_id', $ctx['order']->id)->firstOrFail();
        $schedules = InstallmentSchedule::query()
            ->where('contract_id', $contract->id)
            ->orderBy('installment_number')
            ->get();

        $this->assertCount(7, $schedules);
        $this->assertSame(120.0, (float) $schedules[0]->amount);
        $this->assertSame(180.0, (float) $schedules[6]->amount);
        $this->assertEqualsWithDelta(900.0, (float) $schedules->sum('amount'), 0.01);
    }
}
