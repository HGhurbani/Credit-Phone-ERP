<?php

namespace Tests\Feature\Cash;

use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_expense_create_permission_required(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->forTenant($tenant->id, $branch->id)->create();
        $user->assignRole('sales_agent');
        Sanctum::actingAs($user);

        $this->postJson('/api/expenses', [
            'branch_id' => $branch->id,
            'category' => 'General',
            'amount' => 50,
            'expense_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_expense_with_cashbox_reduces_balance_and_logs_transaction(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $cashbox = Cashbox::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Main',
            'opening_balance' => 1000,
            'current_balance' => 1000,
            'is_active' => true,
        ]);

        $res = $this->postJson('/api/expenses', [
            'branch_id' => $branch->id,
            'cashbox_id' => $cashbox->id,
            'category' => 'Rent',
            'amount' => 200,
            'expense_date' => now()->toDateString(),
        ]);

        $res->assertCreated();
        $this->assertSame('800.00', $cashbox->fresh()->current_balance);

        $this->assertSame(1, CashTransaction::where('cashbox_id', $cashbox->id)
            ->where('transaction_type', CashTransaction::TYPE_EXPENSE_OUT)
            ->where('direction', 'out')
            ->count());
    }

    public function test_expense_fails_when_insufficient_cash(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $cashbox = Cashbox::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Main',
            'opening_balance' => 50,
            'current_balance' => 50,
            'is_active' => true,
        ]);

        $this->postJson('/api/expenses', [
            'branch_id' => $branch->id,
            'cashbox_id' => $cashbox->id,
            'category' => 'Rent',
            'amount' => 200,
            'expense_date' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->assertSame('50.00', $cashbox->fresh()->current_balance);
    }

    public function test_branch_scoped_user_cannot_see_other_branch_cashbox(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id]);

        Cashbox::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchB->id,
            'name' => 'Main',
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $branchUser = User::factory()->forTenant($tenant->id, $branchA->id)->create();
        $branchUser->assignRole('branch_manager');
        Sanctum::actingAs($branchUser);

        $this->getJson('/api/cashboxes')->assertOk();
        $this->assertCount(0, $this->getJson('/api/cashboxes')->json('data'));
    }

    public function test_cashbox_manage_required_for_manual_transaction(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $cashbox = Cashbox::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Main',
            'opening_balance' => 100,
            'current_balance' => 100,
            'is_active' => true,
        ]);

        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('accountant');
        Sanctum::actingAs($user);

        $this->postJson('/api/cashboxes/'.$cashbox->id.'/transactions', [
            'transaction_type' => CashTransaction::TYPE_OTHER_IN,
            'amount' => 10,
            'transaction_date' => now()->toDateString(),
        ])->assertForbidden();
    }
}
