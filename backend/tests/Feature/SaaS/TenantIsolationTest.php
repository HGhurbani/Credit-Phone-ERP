<?php

namespace Tests\Feature\SaaS;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_user_cannot_view_customer_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $customerB = Customer::factory()->create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
        ]);

        $userA = User::factory()->forTenant($tenantA->id)->create();
        $userA->assignRole('company_admin');
        Sanctum::actingAs($userA);

        $this->getJson('/api/customers/'.$customerB->id)->assertForbidden();
    }

    public function test_user_cannot_access_order_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $customerB = Customer::factory()->create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
        ]);
        $orderB = Order::factory()->approvedInstallment($tenantB->id, $branchB->id, $customerB->id)->create();

        $userA = User::factory()->forTenant($tenantA->id)->create();
        $userA->assignRole('company_admin');
        Sanctum::actingAs($userA);

        $this->getJson('/api/orders/'.$orderB->id)->assertForbidden();
    }

    public function test_contract_creation_rejects_order_id_from_another_tenant(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();

        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($otherTenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/contracts', $this->validContractPayloadForOrder($ctx['order']));

        $response->assertForbidden();
    }
}
