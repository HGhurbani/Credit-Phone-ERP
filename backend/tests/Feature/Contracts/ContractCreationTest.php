<?php

namespace Tests\Feature\Contracts;

use App\Models\InstallmentContract;
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
}
