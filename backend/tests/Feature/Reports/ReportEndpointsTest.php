<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_branch_performance_counts_duplicate_amounts_correctly(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Branch A']);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Branch B']);
        $customerA = Customer::factory()->forTenantBranch($tenant->id, $branchA->id)->create();
        $customerB = Customer::factory()->forTenantBranch($tenant->id, $branchB->id)->create();

        Order::factory()->approvedInstallment($tenant->id, $branchA->id, $customerA->id, 100)->create([
            'created_at' => '2026-04-05 10:00:00',
        ]);
        Order::factory()->approvedInstallment($tenant->id, $branchA->id, $customerA->id, 100)->create([
            'created_at' => '2026-04-06 10:00:00',
        ]);
        Order::factory()->approvedInstallment($tenant->id, $branchB->id, $customerB->id, 50)->create([
            'created_at' => '2026-04-06 11:00:00',
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchA->id,
            'customer_id' => $customerA->id,
            'receipt_number' => 'RCPT-A1',
            'amount' => 100,
            'payment_method' => 'cash',
            'payment_date' => '2026-04-05',
        ]);
        Payment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchA->id,
            'customer_id' => $customerA->id,
            'receipt_number' => 'RCPT-A2',
            'amount' => 100,
            'payment_method' => 'cash',
            'payment_date' => '2026-04-06',
        ]);

        $admin = User::factory()->forTenant($tenant->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/reports/branch-performance?date_from=2026-04-01&date_to=2026-04-30');

        $response->assertOk()
            ->assertJsonPath('summary.total_branches', 2)
            ->assertJsonPath('summary.total_orders', 3)
            ->assertJsonPath('summary.total_sales', 250)
            ->assertJsonPath('summary.total_collections', 200);

        $branchARow = collect($response->json('data'))->firstWhere('name', 'Branch A');
        $this->assertNotNull($branchARow);
        $this->assertSame(2, $branchARow['total_orders']);
        $this->assertEquals(200.0, $branchARow['total_sales']);
        $this->assertEquals(200.0, $branchARow['total_collections']);
        $this->assertEquals(100.0, $branchARow['avg_order_value']);
        $this->assertEquals(100.0, $branchARow['collection_to_sales_ratio']);
        $this->assertEquals(0.0, $branchARow['outstanding_gap']);
    }

    public function test_active_contracts_report_returns_enriched_summary_and_rows(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Main Branch']);
        $customer = Customer::factory()->forTenantBranch($tenant->id, $branch->id)->create(['name' => 'Test Customer']);

        $contract = InstallmentContract::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'order_id' => null,
            'created_by' => null,
            'contract_number' => 'CNT-1001',
            'financed_amount' => 800,
            'down_payment' => 200,
            'duration_months' => 12,
            'monthly_amount' => 100,
            'total_amount' => 1200,
            'paid_amount' => 300,
            'remaining_amount' => 900,
            'start_date' => '2026-01-01',
            'first_due_date' => '2026-02-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        InstallmentSchedule::create([
            'contract_id' => $contract->id,
            'tenant_id' => $tenant->id,
            'installment_number' => 1,
            'due_date' => '2026-02-01',
            'amount' => 100,
            'paid_amount' => 100,
            'remaining_amount' => 0,
            'status' => 'paid',
            'paid_date' => '2026-02-02',
        ]);

        InstallmentSchedule::create([
            'contract_id' => $contract->id,
            'tenant_id' => $tenant->id,
            'installment_number' => 2,
            'due_date' => '2026-03-01',
            'amount' => 100,
            'paid_amount' => 0,
            'remaining_amount' => 100,
            'status' => 'overdue',
        ]);

        InstallmentSchedule::create([
            'contract_id' => $contract->id,
            'tenant_id' => $tenant->id,
            'installment_number' => 3,
            'due_date' => '2026-05-01',
            'amount' => 100,
            'paid_amount' => 0,
            'remaining_amount' => 100,
            'status' => 'upcoming',
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'receipt_number' => 'RCPT-C1',
            'contract_id' => $contract->id,
            'amount' => 300,
            'payment_method' => 'cash',
            'payment_date' => '2026-02-02',
        ]);

        $admin = User::factory()->forTenant($tenant->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/reports/active-contracts');

        $response->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.portfolio_value', 1200)
            ->assertJsonPath('summary.collection_progress_percent', 25)
            ->assertJsonCount(1, 'data');

        $response->assertJsonStructure([
            'summary' => [
                'total',
                'total_remaining',
                'total_paid',
                'portfolio_value',
                'avg_remaining_per_contract',
                'avg_paid_per_contract',
                'avg_monthly_amount',
                'collection_progress_percent',
            ],
            'data' => [[
                'contract_number',
                'customer',
                'branch',
                'total_amount',
                'paid_amount',
                'remaining_amount',
                'monthly_amount',
                'paid_progress_percent',
                'next_due_date',
                'overdue_installments_count',
                'overdue_amount',
                'last_payment_date',
                'status',
            ]],
        ]);

        $row = $response->json('data.0');
        $this->assertSame('CNT-1001', $row['contract_number']);
        $this->assertSame('Test Customer', $row['customer']);
        $this->assertSame('Main Branch', $row['branch']);
        $this->assertEquals(25.0, $row['paid_progress_percent']);
        $this->assertSame(1, $row['overdue_installments_count']);
        $this->assertEquals(100.0, $row['overdue_amount']);
        $this->assertSame('2026-02-02', $row['last_payment_date']);
    }
}






