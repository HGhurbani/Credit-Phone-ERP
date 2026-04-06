<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_user_without_permission_receives_403_on_contract_create(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'cash_price' => 1000,
        ]);
        \App\Models\Inventory::create([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => 10,
        ]);

        $order = Order::factory()->approvedInstallment($tenant->id, $branch->id, $customer->id)->create();
        \App\Models\OrderItem::factory()->forOrderProduct(
            $order->id,
            $product->id,
            $product->name,
            1000,
            1
        )->create();

        $salesAgent = User::factory()->forTenant($tenant->id, $branch->id)->create();
        $salesAgent->assignRole('sales_agent');

        Sanctum::actingAs($salesAgent);

        $response = $this->postJson('/api/contracts', [
            'order_id' => $order->id,
            'down_payment' => 400,
            'duration_months' => 12,
            'start_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
        ]);

        $response->assertForbidden();
    }

    public function test_company_admin_with_permission_can_create_contract(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'cash_price' => 1000,
        ]);
        \App\Models\Inventory::create([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => 10,
        ]);

        $order = Order::factory()->approvedInstallment($tenant->id, $branch->id, $customer->id)->create();
        \App\Models\OrderItem::factory()->forOrderProduct(
            $order->id,
            $product->id,
            $product->name,
            1000,
            1
        )->create();

        $admin = User::factory()->forTenant($tenant->id)->create();
        $admin->assignRole('company_admin');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/contracts', [
            'order_id' => $order->id,
            'down_payment' => 400,
            'duration_months' => 12,
            'start_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.order.id', $order->id);
    }

    public function test_super_admin_bypasses_permission_middleware(): void
    {
        $super = User::factory()->superAdmin()->create();
        Sanctum::actingAs($super);

        $response = $this->getJson('/api/users');

        $response->assertOk();
    }
}
