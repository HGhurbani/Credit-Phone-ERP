<?php

namespace Tests\Feature\Assistant;

use App\Models\Category;
use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantExpandedModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_assistant_can_create_a_category_via_catalog_module(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create(['locale' => 'ar']);
        $user->assignRole('company_admin');
        $this->seedAssistantSettings($tenant->id);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'output_text' => json_encode([
                    'intent' => 'create',
                    'module' => 'categories',
                    'operation' => 'create',
                    'target' => null,
                    'arguments' => [
                        'name' => 'الإلكترونيات',
                        'name_ar' => 'إلكترونيات',
                        'slug' => 'electronics',
                    ],
                    'needs_clarification' => false,
                    'clarification_question' => null,
                    'requires_delete_confirmation' => false,
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/assistant/messages', [
            'message' => 'أنشئ تصنيف إلكترونيات',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'completed')
            ->assertJsonPath('data.message.planned_action.module', 'categories')
            ->assertJsonPath('data.message.execution_result.data.name', 'الإلكترونيات');

        $this->assertDatabaseHas('categories', [
            'tenant_id' => $tenant->id,
            'name' => 'الإلكترونيات',
            'slug' => 'electronics',
        ]);
    }

    public function test_assistant_can_run_collections_copilot(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();
        $tenant = $ctx['tenant'];
        $user = User::factory()->forTenant($tenant->id)->create(['locale' => 'ar']);
        $user->assignRole('company_admin');
        $this->seedAssistantSettings($tenant->id);

        Sanctum::actingAs($user);

        $this->postJson('/api/contracts', $this->validContractPayloadForOrder($ctx['order']))
            ->assertCreated();

        $contract = InstallmentContract::where('order_id', $ctx['order']->id)->firstOrFail();
        $schedule = InstallmentSchedule::where('contract_id', $contract->id)->orderBy('id')->firstOrFail();
        $schedule->update([
            'status' => 'overdue',
            'due_date' => now()->subDays(10)->toDateString(),
            'remaining_amount' => $schedule->amount,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'output_text' => json_encode([
                    'intent' => 'query',
                    'module' => 'collections',
                    'operation' => 'query',
                    'target' => null,
                    'arguments' => [
                        'collection_type' => 'copilot',
                        'limit' => 5,
                    ],
                    'needs_clarification' => false,
                    'clarification_question' => null,
                    'requires_delete_confirmation' => false,
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        $this->postJson('/api/assistant/messages', [
            'message' => 'شغّل مساعد التحصيل اليومي',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'completed')
            ->assertJsonPath('data.message.planned_action.module', 'collections')
            ->assertJsonPath('data.message.execution_result.data.summary.overdue_installments', 1)
            ->assertJsonPath('data.message.execution_result.data.items.0.contract_number', $contract->contract_number);
    }

    public function test_assistant_can_adjust_stock_from_the_catalog_module(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory(cashPrice: 1000.0, inventoryQty: 10);
        $tenant = $ctx['tenant'];
        $branch = $ctx['branch'];
        $product = $ctx['product'];
        $user = User::factory()->forTenant($tenant->id)->create(['locale' => 'ar']);
        $user->assignRole('company_admin');
        $this->seedAssistantSettings($tenant->id);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'output_text' => json_encode([
                    'intent' => 'update',
                    'module' => 'stock',
                    'operation' => 'update',
                    'target' => (string) $product->id,
                    'arguments' => [
                        'branch_id' => $branch->id,
                        'quantity' => 3,
                        'movement_type' => 'out',
                        'notes' => 'assistant stock adjustment',
                    ],
                    'needs_clarification' => false,
                    'clarification_question' => null,
                    'requires_delete_confirmation' => false,
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/assistant/messages', [
            'message' => 'اصرف 3 من مخزون المنتج',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'completed')
            ->assertJsonPath('data.message.planned_action.module', 'stock')
            ->assertJsonPath('data.message.execution_result.data.stock.before', 10)
            ->assertJsonPath('data.message.execution_result.data.stock.after', 7);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => 7,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'type' => 'out',
            'quantity_before' => 10,
            'quantity_after' => 7,
        ]);
    }

    public function test_super_admin_can_query_platform_plans_via_assistant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $plan = SubscriptionPlan::create([
            'name' => 'Gold',
            'slug' => 'gold',
            'price' => 199,
            'interval' => 'monthly',
            'max_branches' => 5,
            'max_users' => 20,
            'features' => ['reports', 'assistant'],
            'is_active' => true,
        ]);

        $user = User::factory()->forTenant($tenant->id)->create(['locale' => 'en']);
        $user->assignRole('super_admin');
        $this->seedAssistantSettings($tenant->id);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'output_text' => json_encode([
                    'intent' => 'query',
                    'module' => 'platform',
                    'operation' => 'query',
                    'target' => 'Gold',
                    'arguments' => [
                        'resource' => 'plans',
                        'limit' => 5,
                    ],
                    'needs_clarification' => false,
                    'clarification_question' => null,
                    'requires_delete_confirmation' => false,
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/assistant/messages', [
            'message' => 'List the gold subscription plan',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'completed')
            ->assertJsonPath('data.message.planned_action.module', 'platform')
            ->assertJsonPath('data.message.execution_result.data.resource', 'plans')
            ->assertJsonPath('data.message.execution_result.data.items.0.id', $plan->id);
    }

    private function seedAssistantSettings(int $tenantId): void
    {
        Setting::create([
            'tenant_id' => $tenantId,
            'key' => 'assistant_enabled',
            'value' => '1',
            'group' => 'assistant',
            'type' => 'boolean',
        ]);

        Setting::create([
            'tenant_id' => $tenantId,
            'key' => 'assistant_openai_model',
            'value' => 'gpt-5-mini',
            'group' => 'assistant',
            'type' => 'string',
        ]);

        Setting::create([
            'tenant_id' => $tenantId,
            'key' => 'assistant_openai_api_key',
            'value' => Crypt::encryptString('test-secret'),
            'group' => 'assistant',
            'type' => 'encrypted',
        ]);
    }
}
