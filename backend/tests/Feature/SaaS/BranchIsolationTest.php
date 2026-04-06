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

class BranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_branch_scoped_user_only_sees_own_branch_orders(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $custA = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branchA->id]);
        $custB = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branchB->id]);

        Order::factory()->approvedInstallment($tenant->id, $branchA->id, $custA->id)->create();
        Order::factory()->approvedInstallment($tenant->id, $branchB->id, $custB->id)->create();

        $branchUser = User::factory()->forTenant($tenant->id, $branchA->id)->create();
        $branchUser->assignRole('branch_manager');
        Sanctum::actingAs($branchUser);

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($branchA->id, $response->json('data.0.branch.id'));
    }

    public function test_branch_scoped_user_cannot_view_order_from_other_branch(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $custB = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branchB->id]);
        $orderB = Order::factory()->approvedInstallment($tenant->id, $branchB->id, $custB->id)->create();

        $branchUser = User::factory()->forTenant($tenant->id, $branchA->id)->create();
        $branchUser->assignRole('branch_manager');
        Sanctum::actingAs($branchUser);

        $this->getJson('/api/orders/'.$orderB->id)->assertForbidden();
    }

    public function test_branch_scoped_user_cannot_expand_report_scope_with_branch_id_param(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $custA = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branchA->id]);
        $custB = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branchB->id]);

        Order::factory()->approvedInstallment($tenant->id, $branchA->id, $custA->id)->create(['total' => 100]);
        Order::factory()->approvedInstallment($tenant->id, $branchB->id, $custB->id)->create(['total' => 900]);

        $branchUser = User::factory()->forTenant($tenant->id, $branchA->id)->create();
        $branchUser->assignRole('branch_manager');
        Sanctum::actingAs($branchUser);

        $resIgnored = $this->getJson('/api/reports/sales?branch_id='.$branchB->id);
        $resIgnored->assertOk();
        $this->assertSame(1, (int) $resIgnored->json('summary.total_orders'));
        $this->assertEqualsWithDelta(100.0, (float) $resIgnored->json('summary.total_revenue'), 0.01);
    }

    public function test_company_admin_can_filter_reports_to_a_branch_within_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $custA = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branchA->id]);
        $custB = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branchB->id]);

        Order::factory()->approvedInstallment($tenant->id, $branchA->id, $custA->id)->create(['total' => 100]);
        Order::factory()->approvedInstallment($tenant->id, $branchB->id, $custB->id)->create(['total' => 900]);

        $admin = User::factory()->forTenant($tenant->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $onlyB = $this->getJson('/api/reports/sales?branch_id='.$branchB->id);
        $onlyB->assertOk();
        $this->assertSame(1, (int) $onlyB->json('summary.total_orders'));
        $this->assertEqualsWithDelta(900.0, (float) $onlyB->json('summary.total_revenue'), 0.01);
    }

    public function test_company_admin_gets_422_for_branch_id_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);

        $admin = User::factory()->forTenant($tenantA->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/reports/sales?branch_id='.$branchB->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);
    }
}
