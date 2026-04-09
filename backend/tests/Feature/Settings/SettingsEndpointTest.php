<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
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
                'company_cr_number' => 'CR-12345',
                'company_license_number' => 'LIC-7788',
                'company_tax_card_number' => 'TAX-9001',
            ],
        ])
            ->assertOk()
            ->assertJsonFragment(['message' => 'Settings updated.']);

        $this->assertDatabaseHas('settings', [
            'tenant_id' => $tenant->id,
            'key' => 'company_cr_number',
            'value' => 'CR-12345',
            'group' => 'company',
            'type' => 'string',
        ]);
    }

    public function test_settings_get_returns_company_legal_identifiers(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');

        foreach ([
            'company_cr_number' => 'CR-12345',
            'company_license_number' => 'LIC-7788',
            'company_tax_card_number' => 'TAX-9001',
        ] as $key => $value) {
            Setting::create([
                'tenant_id' => $tenant->id,
                'key' => $key,
                'value' => $value,
                'group' => 'company',
                'type' => 'string',
            ]);
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.company_cr_number', 'CR-12345')
            ->assertJsonPath('data.company_license_number', 'LIC-7788')
            ->assertJsonPath('data.company_tax_card_number', 'TAX-9001');
    }

    public function test_settings_get_hides_secret_values_and_returns_configured_flags(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');

        Setting::create([
            'tenant_id' => $tenant->id,
            'key' => 'assistant_openai_api_key',
            'value' => Crypt::encryptString('secret-key'),
            'group' => 'assistant',
            'type' => 'encrypted',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.assistant_openai_api_key_configured', true)
            ->assertJsonMissing(['assistant_openai_api_key' => 'secret-key']);
    }
}
