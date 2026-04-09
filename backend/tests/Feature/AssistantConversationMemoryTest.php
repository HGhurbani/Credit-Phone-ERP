<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantConversationMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_follow_up_answer_without_thread_id_reuses_pending_clarification_thread(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create(['locale' => 'ar']);
        $user->assignRole('company_admin');
        $this->seedAssistantSettings($tenant->id);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()->push([
                'output_text' => json_encode([
                    'intent' => 'create',
                    'module' => 'customers',
                    'operation' => 'create',
                    'target' => null,
                    'arguments' => ['name' => 'محمد علي'],
                    'needs_clarification' => true,
                    'clarification_question' => 'ما هو رقم هاتف العميل؟',
                    'requires_delete_confirmation' => false,
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $firstResponse = $this->postJson('/api/assistant/messages', [
            'message' => 'انشئ العميل محمد علي',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'needs_clarification')
            ->assertJsonPath('data.message.planned_action.arguments.name', 'محمد علي');

        $threadId = $firstResponse->json('data.thread.id');

        $this->postJson('/api/assistant/messages', [
            'message' => 'رقمه 654789',
        ])
            ->assertOk()
            ->assertJsonPath('data.thread.id', $threadId)
            ->assertJsonPath('data.message.status', 'completed')
            ->assertJsonPath('data.message.planned_action.arguments.name', 'محمد علي')
            ->assertJsonPath('data.message.planned_action.arguments.phone', '654789');

        $this->assertDatabaseCount('assistant_threads', 1);
        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenant->id,
            'name' => 'محمد علي',
            'phone' => '654789',
        ]);
    }

    public function test_clear_new_request_without_thread_id_starts_a_new_thread_even_if_previous_thread_needs_clarification(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->forTenantBranch($tenant->id)->create([
            'name' => 'محمد علي',
            'phone' => '111222',
        ]);
        $user = User::factory()->forTenant($tenant->id)->create(['locale' => 'ar']);
        $user->assignRole('company_admin');
        $this->seedAssistantSettings($tenant->id);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push([
                    'output_text' => json_encode([
                        'intent' => 'create',
                        'module' => 'customers',
                        'operation' => 'create',
                        'target' => null,
                        'arguments' => ['name' => 'محمد علي'],
                        'needs_clarification' => true,
                        'clarification_question' => 'ما هو رقم هاتف العميل؟',
                        'requires_delete_confirmation' => false,
                    ], JSON_THROW_ON_ERROR),
                ], 200)
                ->push([
                    'output_text' => json_encode([
                        'intent' => 'query',
                        'module' => 'customers',
                        'operation' => 'query',
                        'target' => 'محمد',
                        'arguments' => ['limit' => 5],
                        'needs_clarification' => false,
                        'clarification_question' => null,
                        'requires_delete_confirmation' => false,
                    ], JSON_THROW_ON_ERROR),
                ], 200),
        ]);

        Sanctum::actingAs($user);

        $firstResponse = $this->postJson('/api/assistant/messages', [
            'message' => 'انشئ العميل محمد علي',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'needs_clarification');

        $firstThreadId = $firstResponse->json('data.thread.id');

        $this->postJson('/api/assistant/messages', [
            'message' => 'ابحث عن العميل محمد',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'completed')
            ->assertJsonPath('data.message.planned_action.module', 'customers')
            ->assertJsonPath('data.message.planned_action.operation', 'query')
            ->assertJsonPath('data.message.execution_result.data.items.0.id', $customer->id)
            ->assertJsonPath('data.thread.id', fn ($id) => $id !== $firstThreadId);

        $this->assertDatabaseCount('assistant_threads', 2);
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
