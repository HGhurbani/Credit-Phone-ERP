<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantMessage;
use App\Models\Customer;
use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Assistant\AssistantActionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AssistantEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_user_with_permission_can_query_assistant_and_receive_a_completed_message(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->forTenantBranch($tenant->id)->create(['name' => 'Ahmed Ali']);
        $user = User::factory()->forTenant($tenant->id)->create(['locale' => 'ar']);
        $user->assignRole('company_admin');
        $this->seedAssistantSettings($tenant->id);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'output_text' => json_encode([
                    'intent' => 'query',
                    'module' => 'customers',
                    'operation' => 'query',
                    'target' => 'Ahmed',
                    'arguments' => ['limit' => 5],
                    'needs_clarification' => false,
                    'clarification_question' => null,
                    'requires_delete_confirmation' => false,
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/assistant/messages', [
            'message' => 'ابحث عن العميل أحمد',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'completed')
            ->assertJsonPath('data.message.planned_action.module', 'customers')
            ->assertJsonPath('data.message.execution_result.data.items.0.id', $customer->id);

        $this->assertDatabaseHas('assistant_threads', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_assistant_rejects_unsupported_module_requests(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create(['locale' => 'ar']);
        $user->assignRole('company_admin');
        $this->seedAssistantSettings($tenant->id);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'output_text' => json_encode([
                    'intent' => 'unsupported',
                    'module' => 'unsupported',
                    'operation' => 'unsupported',
                    'target' => null,
                    'arguments' => [],
                    'needs_clarification' => false,
                    'clarification_question' => null,
                    'requires_delete_confirmation' => false,
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/assistant/messages', [
            'message' => 'سجل دفعة على فاتورة',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'rejected');
    }

    public function test_openai_strict_schema_uses_arguments_json_and_normalizes_it_back_to_arguments(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->forTenantBranch($tenant->id)->create(['name' => 'Ahmed Ali']);
        $user = User::factory()->forTenant($tenant->id)->create(['locale' => 'ar']);
        $user->assignRole('company_admin');
        $this->seedAssistantSettings($tenant->id);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'output_text' => json_encode([
                    'intent' => 'query',
                    'module' => 'customers',
                    'operation' => 'query',
                    'target' => 'Ahmed',
                    'arguments_json' => json_encode(['limit' => 5], JSON_THROW_ON_ERROR),
                    'needs_clarification' => false,
                    'clarification_question' => null,
                    'requires_delete_confirmation' => false,
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/assistant/messages', [
            'message' => 'ابحث عن العميل أحمد',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'completed')
            ->assertJsonPath('data.message.planned_action.arguments.limit', 5)
            ->assertJsonPath('data.message.execution_result.data.items.0.id', $customer->id);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return data_get($payload, 'text.format.name') === 'assistant_plan'
                && data_get($payload, 'text.format.strict') === true
                && data_get($payload, 'text.format.schema.properties.arguments_json.type') === 'string'
                && data_get($payload, 'text.format.schema.required.4') === 'arguments_json'
                && data_get($payload, 'text.format.schema.properties.arguments') === null;
        });
    }

    public function test_delete_requires_confirmation_and_executes_only_after_confirming(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->forTenantBranch($tenant->id)->create(['name' => 'Delete Me']);
        $user = User::factory()->forTenant($tenant->id)->create(['locale' => 'ar']);
        $user->assignRole('company_admin');
        $this->seedAssistantSettings($tenant->id);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'output_text' => json_encode([
                    'intent' => 'delete',
                    'module' => 'customers',
                    'operation' => 'delete',
                    'target' => (string) $customer->id,
                    'arguments' => [],
                    'needs_clarification' => false,
                    'clarification_question' => null,
                    'requires_delete_confirmation' => true,
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/assistant/messages', [
            'message' => 'احذف العميل Delete Me',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'pending_confirmation');

        $messageId = $response->json('data.message.id');
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);

        $this->postJson("/api/assistant/messages/{$messageId}/confirm-delete")
            ->assertOk()
            ->assertJsonPath('data.message.status', 'completed');

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_print_requests_return_a_signed_pdf_download_url_that_serves_a_pdf_file(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->forTenantBranch($tenant->id)->create(['name' => 'Ahmed Ali']);
        $user = User::factory()->forTenant($tenant->id)->create(['locale' => 'ar']);
        $user->assignRole('company_admin');
        $this->seedAssistantSettings($tenant->id);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'output_text' => json_encode([
                    'intent' => 'print',
                    'module' => 'customers',
                    'operation' => 'print',
                    'target' => (string) $customer->id,
                    'arguments' => [],
                    'needs_clarification' => false,
                    'clarification_question' => null,
                    'requires_delete_confirmation' => false,
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/assistant/messages', [
            'message' => 'اطبع كشف حساب العميل '.$customer->id,
        ])->assertOk();

        $downloadUrl = (string) $response->json('data.message.execution_result.data.print_document.download_url');

        $this->assertStringContainsString('/assistant/print/customer_statement/'.$customer->id.'/', $downloadUrl);
        $this->assertStringContainsString('signature=', $downloadUrl);
        $this->assertStringContainsString('expires=', $downloadUrl);

        $path = (string) parse_url($downloadUrl, PHP_URL_PATH);
        $query = (string) parse_url($downloadUrl, PHP_URL_QUERY);

        $pdfResponse = $this->get($path.'?'.$query);

        $pdfResponse->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfResponse->headers->get('content-type'));
    }

    public function test_assistant_returns_clear_error_when_payment_exceeds_contract_remaining(): void
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
        $remaining = (float) InstallmentSchedule::where('contract_id', $contract->id)->sum('remaining_amount');
        $formattedRemaining = number_format($remaining, 2, '.', '');

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'output_text' => json_encode([
                    'intent' => 'create',
                    'module' => 'payments',
                    'operation' => 'create',
                    'target' => null,
                    'arguments' => [
                        'contract_id' => $contract->id,
                        'amount' => $remaining + 100,
                        'payment_method' => 'cash',
                    ],
                    'needs_clarification' => false,
                    'clarification_question' => null,
                    'requires_delete_confirmation' => false,
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        $this->postJson('/api/assistant/messages', [
            'message' => 'سجل دفعة أعلى من المتبقي',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'rejected')
            ->assertJsonPath('data.message.assistant_message', 'تعذر إكمال الطلب: لا يمكن تسجيل الدفعة لأن المتبقي على العقد هو '.$formattedRemaining.' فقط.')
            ->assertJsonPath('data.message.execution_result.data.validation_errors.amount.0', 'لا يمكن تسجيل الدفعة لأن المتبقي على العقد هو '.$formattedRemaining.' فقط.');
    }

    public function test_assistant_returns_clear_not_found_message_for_missing_records(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create(['locale' => 'ar']);
        $user->assignRole('company_admin');
        $this->seedAssistantSettings($tenant->id);

        $mock = Mockery::mock(AssistantActionService::class);
        $mock->shouldReceive('execute')
            ->once()
            ->andThrow(new ModelNotFoundException());
        $this->app->instance(AssistantActionService::class, $mock);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'output_text' => json_encode([
                    'intent' => 'query',
                    'module' => 'customers',
                    'operation' => 'query',
                    'target' => 'مفقود',
                    'arguments' => [],
                    'needs_clarification' => false,
                    'clarification_question' => null,
                    'requires_delete_confirmation' => false,
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/assistant/messages', [
            'message' => 'اعرض العميل المفقود',
        ])
            ->assertOk()
            ->assertJsonPath('data.message.status', 'rejected')
            ->assertJsonPath('data.message.assistant_message', 'تعذر العثور على السجل المطلوب. تأكد من الرقم أو الاسم ثم حاول مرة أخرى.');
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







