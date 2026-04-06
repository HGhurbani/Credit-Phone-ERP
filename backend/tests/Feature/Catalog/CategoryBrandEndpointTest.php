<?php

namespace Tests\Feature\Catalog;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryBrandEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_category_create_requires_permission(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('sales_agent');
        Sanctum::actingAs($user);

        $this->postJson('/api/categories', [
            'name' => 'Phones',
        ])->assertForbidden();
    }

    public function test_category_create_succeeds_when_permitted(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $this->postJson('/api/categories', [
            'name' => 'Phones',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Phones');
    }

    public function test_brand_create_requires_permission(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('sales_agent');
        Sanctum::actingAs($user);

        $this->postJson('/api/brands', [
            'name' => 'Acme',
        ])->assertForbidden();
    }

    public function test_brand_create_succeeds_when_permitted(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $this->postJson('/api/brands', [
            'name' => 'Acme',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Acme');
    }

    public function test_roles_list_requires_permission(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('collector');
        Sanctum::actingAs($user);

        $this->getJson('/api/roles')->assertForbidden();
    }

    public function test_roles_list_returns_expected_structure_and_excludes_super_admin(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/roles');
        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name']]]);

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertNotContains('super_admin', $names);
        $this->assertContains('company_admin', $names);
    }
}
