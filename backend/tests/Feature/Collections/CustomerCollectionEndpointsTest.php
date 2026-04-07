<?php

namespace Tests\Feature\Collections;

use App\Models\Branch;
use App\Models\CollectionFollowUp;
use App\Models\Customer;
use App\Models\InstallmentContract;
use App\Models\PromiseToPay;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerCollectionEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    private function makeActiveContract(Tenant $tenant, Branch $branch, Customer $customer): InstallmentContract
    {
        return InstallmentContract::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'order_id' => null,
            'created_by' => null,
            'contract_number' => 'TST-'.uniqid(),
            'financed_amount' => 1000,
            'down_payment' => 0,
            'duration_months' => 12,
            'monthly_amount' => 100,
            'total_amount' => 1200,
            'paid_amount' => 0,
            'remaining_amount' => 1200,
            'start_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
    }

    public function test_statement_ok_for_company_admin_with_expected_shape(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $this->makeActiveContract($tenant, $branch, $customer);

        $admin = User::factory()->forTenant($tenant->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $this->getJson("/api/customers/{$customer->id}/statement")
            ->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonStructure([
                'data' => [
                    'generated_at',
                    'customer' => ['id', 'name', 'phone'],
                    'summary' => ['installments_outstanding', 'invoice_balance', 'total_outstanding', 'total_paid'],
                    'active_contracts',
                    'overdue_installments',
                    'open_invoices',
                    'latest_payments',
                    'latest_customer_notes',
                    'latest_collection_follow_ups',
                ],
            ]);
    }

    public function test_collector_from_other_branch_denied_statement(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $customerB = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branchB->id]);
        $this->makeActiveContract($tenant, $branchB, $customerB);

        $collector = User::factory()->forTenant($tenant->id, $branchA->id)->create();
        $collector->assignRole('collector');
        Sanctum::actingAs($collector);

        $this->getJson("/api/customers/{$customerB->id}/statement")->assertForbidden();
    }

    public function test_collector_same_branch_can_view_statement_and_create_follow_up_and_promise(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $contract = $this->makeActiveContract($tenant, $branch, $customer);

        $collector = User::factory()->forTenant($tenant->id, $branch->id)->create();
        $collector->assignRole('collector');
        Sanctum::actingAs($collector);

        $this->getJson("/api/customers/{$customer->id}/statement")->assertOk();

        $this->postJson("/api/customers/{$customer->id}/follow-ups", [
            'outcome' => 'contacted',
            'contract_id' => $contract->id,
            'priority' => 'high',
            'note' => 'Called customer.',
            'next_follow_up_date' => now()->addDays(3)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.outcome', 'contacted');

        $this->assertSame(1, CollectionFollowUp::where('customer_id', $customer->id)->count());

        $this->postJson("/api/customers/{$customer->id}/promises-to-pay", [
            'contract_id' => $contract->id,
            'promised_amount' => '500.00',
            'promised_date' => now()->addWeek()->toDateString(),
            'note' => 'Will pay next week',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(1, PromiseToPay::where('customer_id', $customer->id)->count());
    }

    public function test_other_tenant_denied_statement(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $customerB = Customer::factory()->create(['tenant_id' => $tenantB->id, 'branch_id' => $branchB->id]);

        $userA = User::factory()->forTenant($tenantA->id)->create();
        $userA->assignRole('company_admin');
        Sanctum::actingAs($userA);

        $this->getJson("/api/customers/{$customerB->id}/statement")->assertForbidden();
    }

    public function test_user_without_statement_permission_denied(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

        $user = User::factory()->forTenant($tenant->id, $branch->id)->create();
        $user->assignRole('sales_agent');
        Sanctum::actingAs($user);

        $this->getJson("/api/customers/{$customer->id}/statement")->assertForbidden();
    }

    public function test_follow_up_with_foreign_contract_returns_422(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $other = Customer::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $foreignContract = $this->makeActiveContract($tenant, $branch, $other);

        $collector = User::factory()->forTenant($tenant->id, $branch->id)->create();
        $collector->assignRole('collector');
        Sanctum::actingAs($collector);

        $this->postJson("/api/customers/{$customer->id}/follow-ups", [
            'outcome' => 'contacted',
            'contract_id' => $foreignContract->id,
        ])->assertStatus(422);
    }
}
