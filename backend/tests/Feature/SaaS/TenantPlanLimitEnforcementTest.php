<?php

namespace Tests\Feature\SaaS;

use App\Models\Branch;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantPlanLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_company_admin_cannot_create_user_beyond_plan_limit(): void
    {
        [$tenant, $admin] = $this->createTenantContext(maxBranches: 3, maxUsers: 1);

        Sanctum::actingAs($admin);

        $this->postJson('/api/users', [
            'name' => 'Second User',
            'email' => 'second-user@example.com',
            'password' => 'secret123',
            'role' => 'sales_agent',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('tenant');
    }

    public function test_company_admin_cannot_create_branch_beyond_plan_limit(): void
    {
        [$tenant, $admin] = $this->createTenantContext(maxBranches: 1, maxUsers: 5);

        Sanctum::actingAs($admin);

        $this->postJson('/api/branches', [
            'name' => 'Second Branch',
            'code' => 'B2',
            'is_active' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('tenant');
    }

    private function createTenantContext(int $maxBranches, int $maxUsers): array
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'code' => 'MAIN',
            'is_main' => true,
        ]);

        $admin = User::factory()->forTenant($tenant->id, $branch->id)->create();
        $admin->assignRole('company_admin');

        $plan = SubscriptionPlan::create([
            'name' => 'Starter',
            'slug' => "starter-{$tenant->id}",
            'price' => 99,
            'interval' => 'monthly',
            'max_branches' => $maxBranches,
            'max_users' => $maxUsers,
            'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        return [$tenant, $admin];
    }
}
