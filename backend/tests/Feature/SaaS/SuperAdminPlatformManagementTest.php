<?php

namespace Tests\Feature\SaaS;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminPlatformManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_super_admin_dashboard_returns_platform_overview(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'trial']);
        $plan = SubscriptionPlan::create([
            'name' => 'Growth',
            'slug' => 'growth',
            'price' => 399,
            'interval' => 'monthly',
            'max_branches' => 5,
            'max_users' => 20,
            'is_active' => true,
        ]);
        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(10),
        ]);

        $super = User::factory()->superAdmin()->create();
        $super->assignRole('super_admin');

        Sanctum::actingAs($super);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.total_tenants', 1)
            ->assertJsonPath('stats.active_subscriptions', 1)
            ->assertJsonCount(1, 'recent_tenants')
            ->assertJsonCount(1, 'expiring_subscriptions');
    }

    public function test_super_admin_can_manage_tenants_and_subscriptions(): void
    {
        $super = User::factory()->superAdmin()->create();
        $super->assignRole('super_admin');
        Sanctum::actingAs($super);

        $plan = SubscriptionPlan::create([
            'name' => 'Basic',
            'slug' => 'basic',
            'price' => 199,
            'interval' => 'monthly',
            'max_branches' => 1,
            'max_users' => 5,
            'is_active' => true,
        ]);

        $tenantResponse = $this->postJson('/api/platform/tenants', [
            'name' => 'Platform Tenant',
            'slug' => 'platform-tenant',
            'email' => 'tenant@example.com',
            'status' => 'active',
            'currency' => 'QAR',
            'timezone' => 'Asia/Qatar',
            'locale' => 'ar',
            'admin_name' => 'Platform Admin',
            'admin_email' => 'platform-admin@example.com',
            'admin_password' => 'secret123',
            'plan_id' => $plan->id,
        ])->assertCreated();

        $tenantId = $tenantResponse->json('data.id');

        $this->assertDatabaseHas('branches', [
            'tenant_id' => $tenantId,
            'code' => 'MAIN',
            'is_main' => true,
        ]);

        $this->assertDatabaseHas('users', [
            'tenant_id' => $tenantId,
            'email' => 'platform-admin@example.com',
            'is_super_admin' => false,
        ]);

        $this->postJson('/api/platform/subscriptions', [
            'tenant_id' => $tenantId,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.tenant.id', $tenantId)
            ->assertJsonPath('data.plan.id', $plan->id);

        $this->getJson('/api/platform/tenants')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/platform/subscriptions')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_super_admin_can_impersonate_tenant_admin(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $admin = User::factory()->forTenant($tenant->id)->create([
            'email' => 'tenant-admin@example.com',
        ]);
        $admin->assignRole('company_admin');

        $super = User::factory()->superAdmin()->create();
        $super->assignRole('super_admin');
        Sanctum::actingAs($super);

        $this->postJson("/api/platform/tenants/{$tenant->id}/impersonate")
            ->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'email', 'roles', 'tenant_id'],
            ])
            ->assertJsonPath('user.email', 'tenant-admin@example.com')
            ->assertJsonPath('user.tenant_id', $tenant->id);
    }

    public function test_non_super_admin_cannot_access_platform_management(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $admin = User::factory()->forTenant($tenant->id)->create();
        $admin->assignRole('company_admin');

        Sanctum::actingAs($admin);

        $this->getJson('/api/platform/tenants')->assertForbidden();
    }
}
