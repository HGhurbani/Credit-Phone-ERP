<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\Setting;
use App\Support\SettingsCatalog;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        if ($tenantId === null) {
            return response()->json(['data' => []]);
        }

        $settings = [];

        foreach (Setting::where('tenant_id', $tenantId)->get() as $setting) {
            if (SettingsCatalog::isSecret($setting->key)) {
                $settings[$setting->key.'_configured'] = ! empty($setting->getRawOriginal('value'));
                continue;
            }

            $settings[$setting->key] = $setting->value;
        }

        $settings['assistant_openai_api_key_configured'] = TenantSettings::has($tenantId, 'assistant_openai_api_key');
        $settings['assistant_gemini_api_key_configured'] = TenantSettings::has($tenantId, 'assistant_gemini_api_key');
        $settings['telegram_bot_token_configured'] = TenantSettings::has($tenantId, 'telegram_bot_token');
        $settings['telegram_webhook_secret_configured'] = TenantSettings::has($tenantId, 'telegram_webhook_secret');

        $webhookSecret = TenantSettings::raw($tenantId, 'telegram_webhook_secret');
        if ($webhookSecret) {
            $baseUrl = rtrim((string) config('app.url'), '/');
            if ($baseUrl !== '') {
                $settings['telegram_webhook_url'] = $baseUrl.'/api/webhooks/telegram/'.$tenantId.'/'.$webhookSecret;
            }
        }

        return response()->json(['data' => $settings]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        if ($tenantId === null) {
            abort(422, 'No tenant context for settings.');
        }

        $payload = $request->validated()['settings'];

        if (($payload['telegram_enabled'] ?? false)
            && empty($payload['telegram_webhook_secret'])
            && ! TenantSettings::has($tenantId, 'telegram_webhook_secret')) {
            $payload['telegram_webhook_secret'] = Str::random(40);
        }

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (str_ends_with($key, '_configured')) {
                continue;
            }

            if ($key === 'telegram_webhook_url') {
                continue;
            }

            $type = SettingsCatalog::typeForKey($key);

            if (SettingsCatalog::isSecret($key)) {
                if ($value === null || $value === '') {
                    continue;
                }

                $value = Crypt::encryptString((string) $value);
            }

            Setting::updateOrCreate(
                ['tenant_id' => $tenantId, 'key' => $key],
                [
                    'value' => $value,
                    'group' => SettingsCatalog::groupForKey($key),
                    'type' => $type,
                ]
            );
        }

        return response()->json(['message' => 'Settings updated.']);
    }
}
