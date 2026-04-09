<?php

namespace App\Services\Assistant;

use App\Models\AssistantThread;
use App\Models\User;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AssistantPlannerService
{
    public function plan(User $user, string $channel, string $message, ?AssistantThread $thread = null): array
    {
        $tenantId = $user->tenant_id;
        if ($tenantId === null) {
            throw new RuntimeException('No tenant context available for assistant.');
        }

        if (! TenantSettings::bool($tenantId, 'assistant_enabled', false)) {
            throw new RuntimeException('Assistant is disabled for this tenant.');
        }

        $provider = TenantSettings::string($tenantId, 'assistant_provider', 'openai');
        $locale = $user->locale ?: 'ar';
        $requestPayload = $this->userPrompt($user, $channel, $message, $thread);

        $jsonText = match ($provider) {
            'gemini' => $this->planWithGemini($tenantId, $locale, $requestPayload),
            'openai', '' => $this->planWithOpenAi($tenantId, $locale, $requestPayload),
            default => throw new RuntimeException("Unsupported assistant provider: {$provider}"),
        };

        $decoded = json_decode($jsonText, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The AI provider response was not valid JSON.');
        }

        return $this->normalizePlan($provider, $decoded);
    }

    private function planWithOpenAi(int $tenantId, string $locale, string $requestPayload): string
    {
        $apiKey = TenantSettings::raw($tenantId, 'assistant_openai_api_key');
        if (! $apiKey) {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $model = TenantSettings::string($tenantId, 'assistant_openai_model', 'gpt-5-mini');

        $response = Http::timeout(45)
            ->withToken($apiKey)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => $this->systemPrompt($locale, 'openai'),
                        ]],
                    ],
                    [
                        'role' => 'user',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => $requestPayload,
                        ]],
                    ],
                ],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'assistant_plan',
                        'strict' => true,
                        'schema' => $this->openAiSchema(),
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException($this->providerErrorMessage('OpenAI', $response->status(), $response->json() ?: $response->body()));
        }

        return $this->extractOpenAiJsonText($response->json());
    }

    private function planWithGemini(int $tenantId, string $locale, string $requestPayload): string
    {
        $apiKey = TenantSettings::raw($tenantId, 'assistant_gemini_api_key');
        if (! $apiKey) {
            throw new RuntimeException('Gemini API key is not configured.');
        }

        $model = TenantSettings::string($tenantId, 'assistant_gemini_model', 'gemini-2.5-flash');

        $response = Http::timeout(45)
            ->withHeaders([
                'x-goog-api-key' => $apiKey,
            ])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'systemInstruction' => [
                    'parts' => [[
                        'text' => $this->systemPrompt($locale),
                    ]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [[
                        'text' => $requestPayload,
                    ]],
                ]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseJsonSchema' => $this->geminiSchema(),
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException($this->providerErrorMessage('Gemini', $response->status(), $response->json() ?: $response->body()));
        }

        return $this->extractGeminiJsonText($response->json());
    }

    private function systemPrompt(string $locale, string $provider = 'gemini'): string
    {
        $lang = $locale === 'en' ? 'English' : 'Arabic';
        $argumentsInstruction = $provider === 'openai'
            ? '- Put extracted fields into arguments_json as a JSON string that encodes the arguments object. Use "{}" when there are no arguments. Do not return an arguments field.'
            : '- Put extracted fields into arguments as a flat or nested object.';

        return <<<PROMPT
You are an ERP assistant planner. You do not execute anything.
You must return JSON only, matching the provided schema exactly.

Supported modules:
- customers
- products
- categories
- brands
- stock
- collections
- orders
- users
- branches
- suppliers
- purchases
- contracts
- payments
- invoices
- cashboxes
- cash_transactions
- expenses
- reports
- database
- settings
- platform

Supported operations:
- query
- create
- update
- delete
- run
- print

Rules:
- If the latest assistant message in the thread asked for clarification, treat the new user message as a continuation of the same request unless the user clearly starts a different request.
- If the request mentions a module outside the supported ERP sections, return module "unsupported" and operation "unsupported".
- If the request lacks the minimum information needed to perform the action, set needs_clarification=true and ask one concise clarification question in {$lang}.
- Set requires_delete_confirmation=true only for delete operations.
- Use target for the entity identifier or natural-language identifier when the user refers to an existing record.
- {$argumentsInstruction}
- For product creation, category and brand are optional. Do not ask for them unless the user explicitly wants to set them.
- Extract obvious Arabic and English fields directly from natural phrasing when possible. Examples:
  - "انشئ عميل اسمه أحمد ورقمه 555" => module customers, operation create, arguments.name, arguments.phone
  - "أضف منتج آيفون 16 برو ماكس" => module products, operation create, arguments.name
  - "أضف تصنيف اسمه هواتف" => module categories, operation create, arguments.name
  - "أضف ماركة سامسونج" => module brands, operation create, arguments.name
  - "عدّل العميل 15 وخلي الهاتف 777" => module customers, operation update, target 15, arguments.phone
  - "ابحث عن المستخدم خالد" => module users, operation query, target خالد
  - "سجل دفعة 500 على عقد 12" => module payments, operation create, arguments.contract_id, arguments.amount
  - "أضف مورد اسمه النور" => module suppliers, operation create, arguments.name
  - "زد مخزون آيفون 16 بمقدار 5 في فرع الدوحة" => module stock, operation update, target آيفون 16, arguments.quantity=5, arguments.movement_type=in, arguments.branch
  - "اعرض المتأخرات للتحصيل" => module collections, operation query, arguments.collection_type=overdue
  - "أنشئ متابعة تحصيل للعميل أحمد" => module collections, operation create, target أحمد, arguments.collection_action=follow_up
  - "اقترح أولويات التحصيل اليوم" => module collections, operation run, arguments.collection_type=copilot
  - "اعرض المستأجرين النشطين" => module platform, operation query, arguments.resource=tenants, arguments.status=active
  - "سجل مصروف كهرباء 250 اليوم" => module expenses, operation create, arguments.category, arguments.amount, arguments.expense_date
  - "الغ فاتورة 33" => module invoices, operation update, target 33, arguments.status=cancelled
  - "أنشئ صندوق رئيسي لفرع الدوحة" => module cashboxes, operation create, arguments.name, arguments.branch
  - "اطبع العقد 12 PDF" => module contracts, operation print, target 12
  - "اطبع كشف حساب العميل أحمد PDF" => module customers, operation print, target أحمد
  - "اطبع الفاتورة 33" => module invoices, operation print, target 33
  - "اطبع إيصال الدفعة 18" => module payments, operation print, target 18
  - "اطبع سند الصندوق CR-001" => module cash_transactions, operation print, target CR-001
  - "اعطني مبيعات شهر 5 بالتفصيل" => module reports, operation run, arguments.report_type=sales, arguments.details=true
  - "custom sql for top customers" => module database, operation query, arguments.sql contains a read-only SELECT query
- For reports, set module "reports", operation "run", and include arguments.report_type.
- For report requests that mention details, detailed rows, or "بالتفصيل", set arguments.details=true.
- For custom database queries, set module "database", operation "query", and put a read-only SQL statement in arguments.sql.
- For database queries, generate read-only SQL only. Never generate INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, or multiple statements.
- For settings, only plan query or update.
- For category requests, use module "categories".
- For brand requests, use module "brands".
- For stock and inventory requests, use module "stock". Use operation "query" to inspect stock and operation "update" to adjust stock. Put movement_type in arguments as one of: in, out, adjustment.
- For collections requests, use module "collections". Put the requested collection workflow in arguments.collection_type or arguments.collection_action. Supported values include: statement, due_today, overdue, follow_ups, promises_to_pay, reschedule_requests, copilot, follow_up, promise_to_pay, reschedule_request.
- For platform management, use module "platform" and always set arguments.resource to one of: tenants, plans, subscriptions.
- Never include explanation outside JSON.
PROMPT;
    }

    private function userPrompt(User $user, string $channel, string $message, ?AssistantThread $thread): string
    {
        $today = now()->toDateString();
        $threadContext = '';
        if ($thread) {
            $lastMessages = $thread->messages()->latest()->limit(3)->get(['user_message', 'assistant_message'])->reverse()->values();
            if ($lastMessages->isNotEmpty()) {
                $contextLines = [];
                foreach ($lastMessages as $item) {
                    $contextLines[] = 'User: '.$item->user_message;
                    if ($item->assistant_message) {
                        $contextLines[] = 'Assistant: '.$item->assistant_message;
                    }
                }
                $threadContext = "Recent thread context:\n".implode("\n", $contextLines)."\n";
            }
        }

        return <<<PROMPT
User locale: {$user->locale}
Channel: {$channel}
Today: {$today}
User roles: {$user->getRoleNames()->implode(', ')}
User permissions: {$user->getAllPermissions()->pluck('name')->implode(', ')}
{$threadContext}
Interpret the latest message in light of the recent thread context. If the user message looks like a short answer, field list, or value-only follow-up, merge it with the pending request instead of treating it as a brand-new task.

Request:
{$message}
PROMPT;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => ['query', 'create', 'update', 'delete', 'run', 'print', 'unsupported'],
                ],
                'module' => [
                    'type' => 'string',
                    'enum' => ['customers', 'products', 'categories', 'brands', 'stock', 'collections', 'orders', 'users', 'branches', 'suppliers', 'purchases', 'contracts', 'payments', 'invoices', 'cashboxes', 'cash_transactions', 'expenses', 'reports', 'database', 'settings', 'platform', 'unsupported'],
                ],
                'operation' => [
                    'type' => 'string',
                    'enum' => ['query', 'create', 'update', 'delete', 'run', 'print', 'unsupported'],
                ],
                'target' => [
                    'type' => ['string', 'null'],
                ],
                'arguments' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'properties' => (object) [],
                ],
                'needs_clarification' => [
                    'type' => 'boolean',
                ],
                'clarification_question' => [
                    'type' => ['string', 'null'],
                ],
                'requires_delete_confirmation' => [
                    'type' => 'boolean',
                ],
            ],
            'required' => [
                'intent',
                'module',
                'operation',
                'target',
                'arguments',
                'needs_clarification',
                'clarification_question',
                'requires_delete_confirmation',
            ],
        ];
    }

    private function openAiSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => ['query', 'create', 'update', 'delete', 'run', 'print', 'unsupported'],
                ],
                'module' => [
                    'type' => 'string',
                    'enum' => ['customers', 'products', 'categories', 'brands', 'stock', 'collections', 'orders', 'users', 'branches', 'suppliers', 'purchases', 'contracts', 'payments', 'invoices', 'cashboxes', 'cash_transactions', 'expenses', 'reports', 'database', 'settings', 'platform', 'unsupported'],
                ],
                'operation' => [
                    'type' => 'string',
                    'enum' => ['query', 'create', 'update', 'delete', 'run', 'print', 'unsupported'],
                ],
                'target' => [
                    'type' => ['string', 'null'],
                ],
                'arguments_json' => [
                    'type' => 'string',
                ],
                'needs_clarification' => [
                    'type' => 'boolean',
                ],
                'clarification_question' => [
                    'type' => ['string', 'null'],
                ],
                'requires_delete_confirmation' => [
                    'type' => 'boolean',
                ],
            ],
            'required' => [
                'intent',
                'module',
                'operation',
                'target',
                'arguments_json',
                'needs_clarification',
                'clarification_question',
                'requires_delete_confirmation',
            ],
        ];
    }

    private function geminiSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => ['query', 'create', 'update', 'delete', 'run', 'print', 'unsupported'],
                ],
                'module' => [
                    'type' => 'string',
                    'enum' => ['customers', 'products', 'categories', 'brands', 'stock', 'collections', 'orders', 'users', 'branches', 'suppliers', 'purchases', 'contracts', 'payments', 'invoices', 'cashboxes', 'cash_transactions', 'expenses', 'reports', 'database', 'settings', 'platform', 'unsupported'],
                ],
                'operation' => [
                    'type' => 'string',
                    'enum' => ['query', 'create', 'update', 'delete', 'run', 'print', 'unsupported'],
                ],
                'target' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'arguments' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
                'needs_clarification' => [
                    'type' => 'boolean',
                ],
                'clarification_question' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'requires_delete_confirmation' => [
                    'type' => 'boolean',
                ],
            ],
            'required' => [
                'intent',
                'module',
                'operation',
                'target',
                'arguments',
                'needs_clarification',
                'clarification_question',
                'requires_delete_confirmation',
            ],
        ];
    }

    private function extractOpenAiJsonText(array $payload): string
    {
        if (isset($payload['output_text']) && is_string($payload['output_text']) && $payload['output_text'] !== '') {
            return $payload['output_text'];
        }

        foreach ($payload['output'] ?? [] as $outputItem) {
            foreach ($outputItem['content'] ?? [] as $contentItem) {
                if (($contentItem['type'] ?? null) === 'output_text' && isset($contentItem['text']) && is_string($contentItem['text'])) {
                    return $contentItem['text'];
                }
            }
        }

        throw new RuntimeException('Could not extract structured JSON from OpenAI response.');
    }

    private function extractGeminiJsonText(array $payload): string
    {
        foreach ($payload['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (isset($part['text']) && is_string($part['text']) && trim($part['text']) !== '') {
                    return $part['text'];
                }
            }
        }

        throw new RuntimeException('Could not extract structured JSON from Gemini response.');
    }

    private function normalizePlan(string $provider, array $plan): array
    {
        if (($provider === 'openai' || $provider === '') && array_key_exists('arguments_json', $plan)) {
            $plan['arguments'] = $this->decodeArgumentsJson($plan['arguments_json']);
            unset($plan['arguments_json']);
        }

        if (! is_array($plan['arguments'] ?? null)) {
            $plan['arguments'] = [];
        }

        return $plan;
    }

    private function decodeArgumentsJson(mixed $argumentsJson): array
    {
        if (is_array($argumentsJson)) {
            return $argumentsJson;
        }

        if (! is_string($argumentsJson) || trim($argumentsJson) === '') {
            return [];
        }

        $decoded = json_decode($argumentsJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function providerErrorMessage(string $provider, int $status, mixed $body): string
    {
        if (is_array($body)) {
            $errorMessage = $body['error']['message'] ?? $body['message'] ?? null;
            if (is_string($errorMessage) && $errorMessage !== '') {
                return "{$provider} planning request failed: {$errorMessage}";
            }
        }

        if (is_string($body) && trim($body) !== '') {
            return "{$provider} planning request failed: ".trim($body);
        }

        return "{$provider} planning request failed with status {$status}.";
    }
}
