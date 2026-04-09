<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\Tenant;
use App\Support\TenantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, int $tenant, string $secret): JsonResponse
    {
        $tenantModel = Tenant::query()->findOrFail($tenant);
        $expectedSecret = TenantSettings::raw($tenantModel->id, 'telegram_webhook_secret');

        if (! $expectedSecret || ! hash_equals($expectedSecret, $secret)) {
            abort(404);
        }

        ProcessTelegramUpdateJob::dispatch($tenantModel->id, $request->all());

        return response()->json(['ok' => true]);
    }
}
