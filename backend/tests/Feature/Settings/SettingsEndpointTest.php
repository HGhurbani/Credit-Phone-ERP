<?php

namespace Tests\Feature\Settings;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_settings_get_returns_data_for_tenant_user(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_settings_put_rejects_invalid_keys(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $this->putJson('/api/settings', [
            'settings' => [
                'bad key!' => 'x',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['settings']);
    }

    public function test_settings_put_updates_valid_keys(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $this->putJson('/api/settings', [
            'settings' => [
                'installment_monthly_percent_of_cash' => '6',
            ],
        ])
            ->assertOk()
            ->assertJsonFragment(['message' => 'Settings updated.']);
    }
}
