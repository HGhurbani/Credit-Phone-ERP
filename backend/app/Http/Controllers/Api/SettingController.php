<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        if ($tenantId === null) {
            return response()->json(['data' => []]);
        }

        $settings = Setting::where('tenant_id', $tenantId)
            ->get()
            ->pluck('value', 'key');

        return response()->json(['data' => $settings]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        if ($tenantId === null) {
            abort(422, 'No tenant context for settings.');
        }

        foreach ($request->validated()['settings'] as $key => $value) {
            Setting::updateOrCreate(
                ['tenant_id' => $tenantId, 'key' => $key],
                ['value' => $value, 'group' => 'general']
            );
        }

        return response()->json(['message' => 'Settings updated.']);
    }
}
