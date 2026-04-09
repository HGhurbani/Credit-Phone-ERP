<?php

namespace App\Services\Assistant;

use App\Support\TenantSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TelegramBotService
{
    public function sendMessage(int $tenantId, string $chatId, string $text): void
    {
        if (! TenantSettings::bool($tenantId, 'telegram_enabled', false)) {
            return;
        }

        $token = TenantSettings::raw($tenantId, 'telegram_bot_token');
        if (! $token) {
            return;
        }

        Http::asForm()
            ->timeout(30)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);
    }

    public function sendDocument(int $tenantId, string $chatId, string $documentUrl, ?string $caption = null): bool
    {
        if (! TenantSettings::bool($tenantId, 'telegram_enabled', false)) {
            return false;
        }

        $token = TenantSettings::raw($tenantId, 'telegram_bot_token');
        if (! $token) {
            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'document' => $documentUrl,
        ];

        if ($caption !== null && trim($caption) !== '') {
            $payload['caption'] = Str::limit(trim($caption), 1024, '...');
        }

        $response = Http::asForm()
            ->timeout(60)
            ->post("https://api.telegram.org/bot{$token}/sendDocument", $payload);

        return $response->successful();
    }
}
