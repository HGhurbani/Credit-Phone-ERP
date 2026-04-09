<?php

namespace App\Jobs;

use App\Services\Assistant\AssistantOrchestratorService;
use App\Services\Assistant\TelegramAssistantResponseFormatter;
use App\Services\Assistant\TelegramBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $tenantId,
        public array $payload,
    ) {}

    public function handle(
        AssistantOrchestratorService $assistant,
        TelegramAssistantResponseFormatter $formatter,
        TelegramBotService $telegram,
    ): void {
        $message = $this->payload['message'] ?? $this->payload['edited_message'] ?? null;
        if (! is_array($message)) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $chatId = isset($message['chat']['id']) ? (string) $message['chat']['id'] : null;
        $telegramUserId = isset($message['from']['id']) ? (string) $message['from']['id'] : null;
        $telegramUsername = $message['from']['username'] ?? null;

        if ($text === '' || $chatId === null || $telegramUserId === null) {
            return;
        }

        if (Str::startsWith($text, '/link ')) {
            $code = trim(Str::after($text, '/link '));
            $result = $assistant->linkTelegramAccount($this->tenantId, $telegramUserId, $chatId, $telegramUsername, $code);
            $telegram->sendMessage($this->tenantId, $chatId, (string) ($result['summary'] ?? 'OK'));

            return;
        }

        if ($text === '/unlink') {
            $assistant->unlinkTelegramByExternalId($this->tenantId, $telegramUserId);
            $telegram->sendMessage($this->tenantId, $chatId, 'تم إلغاء الربط بنجاح.');

            return;
        }

        $linkedUser = $assistant->getLinkedUserByTelegram($this->tenantId, $telegramUserId);
        if (! $linkedUser) {
            $telegram->sendMessage($this->tenantId, $chatId, 'حساب Telegram هذا غير مربوط بعد. افتح النظام وأنشئ رمز ربط ثم أرسل /link CODE');

            return;
        }

        if (Str::startsWith(Str::upper($text), 'CONFIRM ')) {
            $code = trim(Str::after($text, 'CONFIRM '));
            $result = $assistant->confirmDeleteByCode($linkedUser, $code, 'telegram');
            $executionResult = $result['message']?->execution_result_json;
            $assistantMessage = $result['message']?->assistant_message ?? $result['result']['summary'] ?? null;

            foreach ($formatter->format(is_array($executionResult) ? $executionResult : [], $assistantMessage, $linkedUser->locale ?? 'ar') as $reply) {
                $telegram->sendMessage($this->tenantId, $chatId, $reply);
            }

            return;
        }

        $result = $assistant->processMessage($linkedUser, $text, 'telegram');
        $executionResult = $result['message']?->execution_result_json;
        $assistantMessage = $result['message']?->assistant_message ?? null;
        $printDocument = is_array($executionResult['data']['print_document'] ?? null)
            ? $executionResult['data']['print_document']
            : null;

        if ($printDocument) {
            $documentUrl = $printDocument['telegram_document_url'] ?? $printDocument['download_url'] ?? null;

            if (is_string($documentUrl) && $documentUrl !== '') {
                $sent = $telegram->sendDocument(
                    $this->tenantId,
                    $chatId,
                    $documentUrl,
                    $assistantMessage ?? ($executionResult['summary'] ?? null)
                );

                if ($sent) {
                    return;
                }
            }
        }

        foreach ($formatter->format(is_array($executionResult) ? $executionResult : [], $assistantMessage, $linkedUser->locale ?? 'ar') as $reply) {
            $telegram->sendMessage($this->tenantId, $chatId, $reply);
        }
    }
}
