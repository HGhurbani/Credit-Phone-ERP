<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantMessage;
use App\Services\Assistant\AssistantOrchestratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function __construct(
        private readonly AssistantOrchestratorService $assistant,
    ) {}

    public function threads(Request $request): JsonResponse
    {
        $threads = $this->assistant->listThreads($request->user());
        $telegramLink = $this->assistant->getTelegramLink($request->user());

        return response()->json([
            'data' => $threads->map(fn ($thread) => [
                'id' => $thread->id,
                'channel' => $thread->channel,
                'title' => $thread->title,
                'last_message_at' => $thread->last_message_at?->toIso8601String(),
                'messages_count' => $thread->messages_count,
                'latest_message' => optional($thread->messages->first(), fn ($message) => $this->messagePayload($message)),
            ])->values(),
            'telegram' => [
                'linked' => $telegramLink !== null,
                'username' => $telegramLink?->telegram_username,
                'chat_id' => $telegramLink?->telegram_chat_id,
                'linked_at' => $telegramLink?->linked_at?->toIso8601String(),
            ],
        ]);
    }

    public function showThread(Request $request, int $id): JsonResponse
    {
        $thread = $this->assistant->getThread($request->user(), $id);

        return response()->json([
            'data' => [
                'id' => $thread->id,
                'channel' => $thread->channel,
                'title' => $thread->title,
                'last_message_at' => $thread->last_message_at?->toIso8601String(),
                'messages' => $thread->messages->map(fn ($message) => $this->messagePayload($message))->values(),
            ],
        ]);
    }

    public function storeMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'thread_id' => ['nullable', 'integer'],
        ]);

        $result = $this->assistant->processMessage(
            $request->user(),
            $validated['message'],
            'web',
            $validated['thread_id'] ?? null
        );

        return response()->json([
            'data' => [
                'thread' => [
                    'id' => $result['thread']->id,
                    'channel' => $result['thread']->channel,
                    'title' => $result['thread']->title,
                    'last_message_at' => $result['thread']->last_message_at?->toIso8601String(),
                ],
                'message' => $this->messagePayload($result['message']),
            ],
        ]);
    }

    public function confirmDelete(Request $request, int $id): JsonResponse
    {
        $message = AssistantMessage::query()->findOrFail($id);
        $result = $this->assistant->confirmDelete($request->user(), $message, 'web');

        return response()->json([
            'data' => [
                'thread' => $result['thread'] ? [
                    'id' => $result['thread']->id,
                    'channel' => $result['thread']->channel,
                    'title' => $result['thread']->title,
                    'last_message_at' => $result['thread']->last_message_at?->toIso8601String(),
                ] : null,
                'message' => $result['message'] ? $this->messagePayload($result['message']) : null,
                'result' => $result['result'] ?? null,
            ],
        ]);
    }

    public function generateLinkCode(Request $request): JsonResponse
    {
        $payload = $this->assistant->generateTelegramLinkCode($request->user());

        return response()->json(['data' => $payload], 201);
    }

    public function unlinkTelegram(Request $request): JsonResponse
    {
        $this->assistant->unlinkTelegram($request->user());

        return response()->json(['message' => 'Telegram link removed.']);
    }

    private function messagePayload(AssistantMessage $message): array
    {
        return [
            'id' => $message->id,
            'thread_id' => $message->thread_id,
            'channel' => $message->channel,
            'user_message' => $message->user_message,
            'assistant_message' => $message->assistant_message,
            'planned_action' => $message->planned_action_json,
            'execution_result' => $message->execution_result_json,
            'status' => $message->status,
            'requires_delete_confirmation' => $message->requires_delete_confirmation,
            'confirmation_expires_at' => $message->confirmation_expires_at?->toIso8601String(),
            'confirmed_at' => $message->confirmed_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
