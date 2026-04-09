<?php

namespace App\Services\Assistant;

use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\TelegramLinkCode;
use App\Models\TelegramUserLink;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AssistantOrchestratorService
{
    public function __construct(
        private readonly AssistantPlannerService $planner,
        private readonly AssistantPolicyService $policy,
        private readonly AssistantActionService $actions,
    ) {}

    public function listThreads(User $user)
    {
        return AssistantThread::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->withCount('messages')
            ->with(['messages' => fn ($query) => $query->latest()->limit(1)])
            ->orderByDesc('last_message_at')
            ->get();
    }

    public function getThread(User $user, int $threadId): AssistantThread
    {
        return AssistantThread::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->with('messages')
            ->findOrFail($threadId);
    }

    public function processMessage(User $user, string $message, string $channel = 'web', ?int $threadId = null): array
    {
        $thread = $this->resolveThread($user, $channel, $threadId, $message);
        $plan = $this->continuePendingClarification($thread, $message);

        if ($plan === null) {
            try {
                $plan = $this->planner->plan($user, $channel, $message, $thread);
            } catch (RuntimeException $exception) {
                $messageModel = $this->storeMessage($thread, $user, $channel, $message, null, [
                    'status' => 'error',
                    'summary' => $this->localized($user, 'تعذر تشغيل الوكيل حالياً: '.$exception->getMessage(), 'The assistant could not run right now: '.$exception->getMessage()),
                    'data' => [],
                ]);

                return ['thread' => $thread, 'message' => $messageModel];
            }
        }

        $plan = $this->hydrateClarificationPlan($thread, $message, $plan);
        $plan = $this->attachRequestContext($plan, $message);

        if (($plan['needs_clarification'] ?? false) === true) {
            $messageModel = $this->storeMessage($thread, $user, $channel, $message, $plan, [
                'status' => 'needs_clarification',
                'summary' => (string) ($plan['clarification_question'] ?? $this->localized($user, 'أحتاج توضيحاً إضافياً لتنفيذ الطلب.', 'I need one more clarification to complete this request.')),
                'data' => $this->clarificationPayloadFromPlan($plan),
            ]);

            return ['thread' => $thread, 'message' => $messageModel];
        }

        $denial = $this->policy->denialReason($user, $plan);
        if ($denial !== null) {
            $messageModel = $this->storeMessage($thread, $user, $channel, $message, $plan, [
                'status' => 'rejected',
                'summary' => $denial,
                'data' => [],
            ]);

            return ['thread' => $thread, 'message' => $messageModel];
        }

        if (($plan['requires_delete_confirmation'] ?? false) === true) {
            $code = Str::upper(Str::random(6));
            $summary = $channel === 'telegram'
                ? $this->localized(
                    $user,
                    "تم تجهيز طلب الحذف. للتأكيد أرسل: CONFIRM {$code}",
                    "Your delete request is ready. To confirm, send: CONFIRM {$code}"
                )
                : $this->localized(
                    $user,
                    'تم تجهيز طلب الحذف. اضغط تأكيد الحذف لإكمال التنفيذ.',
                    'Your delete request is ready. Press confirm delete to complete it.'
                );

            $messageModel = $this->storeMessage($thread, $user, $channel, $message, $plan, [
                'status' => 'pending_confirmation',
                'summary' => $summary,
                'data' => [
                    'confirmation_code' => $channel === 'telegram' ? $code : null,
                ],
            ], true, $code);

            return ['thread' => $thread, 'message' => $messageModel];
        }

        try {
            $result = $this->actions->execute($user, $plan, $channel);
        } catch (Throwable $exception) {
            $result = $this->executionErrorResult($user, $exception);
        }

        if (($result['status'] ?? null) === 'needs_clarification' && isset($result['data']['clarification']) && is_array($result['data']['clarification'])) {
            $plan['clarification'] = $result['data']['clarification'];
        }

        $messageModel = $this->storeMessage($thread, $user, $channel, $message, $plan, $result);

        return ['thread' => $thread, 'message' => $messageModel];
    }

    public function confirmDelete(User $user, AssistantMessage $message, string $channel = 'web'): array
    {
        $this->assertMessageOwnership($user, $message);

        return $this->completeDeleteConfirmation($user, $message, $channel);
    }

    public function confirmDeleteByCode(User $user, string $code, string $channel = 'telegram'): array
    {
        $pendingMessages = AssistantMessage::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('requires_delete_confirmation', true)
            ->whereNull('confirmed_at')
            ->where('status', 'pending_confirmation')
            ->where('confirmation_expires_at', '>=', now())
            ->latest()
            ->get();

        foreach ($pendingMessages as $message) {
            if ($message->confirmation_code_hash && Hash::check($code, $message->confirmation_code_hash)) {
                return $this->completeDeleteConfirmation($user, $message, $channel);
            }
        }

        return [
            'thread' => null,
            'message' => null,
            'result' => [
                'status' => 'rejected',
                'summary' => $this->localized($user, 'رمز التأكيد غير صحيح أو منتهي الصلاحية.', 'The confirmation code is invalid or expired.'),
                'data' => [],
            ],
        ];
    }

    public function generateTelegramLinkCode(User $user): array
    {
        $code = Str::upper(Str::random(8));

        TelegramLinkCode::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();

        $record = TelegramLinkCode::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
        ]);

        return [
            'code' => $code,
            'expires_at' => $record->expires_at?->toIso8601String(),
        ];
    }

    public function linkTelegramAccount(int $tenantId, string $telegramUserId, string $chatId, ?string $telegramUsername, string $code): array
    {
        $record = TelegramLinkCode::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('used_at')
            ->where('expires_at', '>=', now())
            ->with('user')
            ->latest()
            ->get()
            ->first(fn (TelegramLinkCode $item) => Hash::check($code, $item->code_hash));

        if (! $record || ! $record->user) {
            return [
                'status' => 'rejected',
                'summary' => (($record?->user?->locale ?? 'ar') === 'en')
                    ? 'The link code is invalid or expired.'
                    : 'رمز الربط غير صحيح أو منتهي الصلاحية.',
                'data' => [],
            ];
        }

        TelegramUserLink::query()
            ->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query
                ->where('user_id', $record->user_id)
                ->orWhere('telegram_user_id', $telegramUserId))
            ->delete();

        TelegramUserLink::create([
            'tenant_id' => $tenantId,
            'user_id' => $record->user_id,
            'telegram_user_id' => $telegramUserId,
            'telegram_chat_id' => $chatId,
            'telegram_username' => $telegramUsername,
            'linked_at' => now(),
            'last_seen_at' => now(),
            'revoked_at' => null,
        ]);

        $record->update(['used_at' => now()]);

        return [
            'status' => 'completed',
            'summary' => $this->localized($record->user, 'تم ربط Telegram بحسابك بنجاح.', 'Telegram has been linked to your account successfully.'),
            'data' => [],
        ];
    }

    public function unlinkTelegram(User $user): bool
    {
        return (bool) TelegramUserLink::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->update([
                'revoked_at' => now(),
                'last_seen_at' => now(),
            ]);
    }

    public function unlinkTelegramByExternalId(int $tenantId, string $telegramUserId): bool
    {
        return (bool) TelegramUserLink::query()
            ->where('tenant_id', $tenantId)
            ->where('telegram_user_id', $telegramUserId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'last_seen_at' => now(),
            ]);
    }

    public function getTelegramLink(User $user): ?TelegramUserLink
    {
        return TelegramUserLink::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->first();
    }

    public function getLinkedUserByTelegram(int $tenantId, string $telegramUserId): ?User
    {
        $link = TelegramUserLink::query()
            ->where('tenant_id', $tenantId)
            ->where('telegram_user_id', $telegramUserId)
            ->whereNull('revoked_at')
            ->with('user.roles', 'user.permissions')
            ->first();

        if (! $link?->user) {
            return null;
        }

        $link->update(['last_seen_at' => now(), 'telegram_chat_id' => $link->telegram_chat_id ?: $telegramUserId]);

        return $link->user;
    }

    private function resolveThread(User $user, string $channel, ?int $threadId, string $message): AssistantThread
    {
        if ($threadId !== null) {
            return AssistantThread::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->findOrFail($threadId);
        }

        $continuationThread = $this->findImplicitContinuationThread($user, $channel, $message);
        if ($continuationThread !== null) {
            return $continuationThread;
        }

        if ($channel === 'telegram') {
            $existing = AssistantThread::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->where('channel', 'telegram')
                ->orderByDesc('last_message_at')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return AssistantThread::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'channel' => $channel,
            'title' => Str::limit(trim($message), 100),
            'last_message_at' => now(),
        ]);
    }

    private function findImplicitContinuationThread(User $user, string $channel, string $message): ?AssistantThread
    {
        $thread = AssistantThread::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->with(['messages' => fn ($query) => $query->latest()->limit(1)])
            ->orderByDesc('last_message_at')
            ->first();

        if (! $thread) {
            return null;
        }

        $latestMessage = $thread->messages->first();
        if (! $latestMessage || $latestMessage->status !== 'needs_clarification') {
            return null;
        }

        if ($thread->last_message_at?->lt(now()->subHours(12))) {
            return null;
        }

        return $this->startsFreshRequest($message) ? null : $thread;
    }

    private function storeMessage(
        AssistantThread $thread,
        User $user,
        string $channel,
        string $userMessage,
        ?array $plan,
        array $result,
        bool $requiresDeleteConfirmation = false,
        ?string $plainConfirmationCode = null
    ): AssistantMessage {
        $message = AssistantMessage::create([
            'thread_id' => $thread->id,
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'channel' => $channel,
            'user_message' => $userMessage,
            'assistant_message' => $result['summary'] ?? null,
            'planned_action_json' => $plan,
            'execution_result_json' => $result,
            'status' => $result['status'] ?? 'completed',
            'requires_delete_confirmation' => $requiresDeleteConfirmation,
            'confirmation_code_hash' => $plainConfirmationCode ? Hash::make($plainConfirmationCode) : null,
            'confirmation_expires_at' => $plainConfirmationCode ? now()->addMinutes(15) : null,
        ]);

        $thread->update([
            'last_message_at' => $message->created_at ?? now(),
            'title' => $thread->title ?: Str::limit(trim($userMessage), 100),
        ]);

        return $message->fresh();
    }

    private function completeDeleteConfirmation(User $user, AssistantMessage $message, string $channel): array
    {
        if (! $message->requires_delete_confirmation || $message->status !== 'pending_confirmation') {
            return [
                'thread' => $message->thread,
                'message' => $message,
                'result' => [
                    'status' => 'rejected',
                    'summary' => $this->localized($user, 'لا يوجد طلب حذف معلق لهذا السجل.', 'There is no pending delete request for this message.'),
                    'data' => [],
                ],
            ];
        }

        if ($message->confirmation_expires_at && Carbon::parse($message->confirmation_expires_at)->isPast()) {
            $message->update([
                'status' => 'expired',
                'assistant_message' => $this->localized($user, 'انتهت صلاحية طلب الحذف. أعد إرسال الطلب من جديد.', 'The delete request expired. Please send it again.'),
            ]);

            return ['thread' => $message->thread, 'message' => $message->fresh()];
        }

        $plan = $message->planned_action_json ?? [];
        $result = $this->actions->execute($user, $plan, $channel, true);

        $message->update([
            'assistant_message' => $result['summary'] ?? null,
            'execution_result_json' => $result,
            'status' => $result['status'] ?? 'completed',
            'confirmed_at' => now(),
            'confirmation_code_hash' => null,
        ]);

        $message->thread?->update(['last_message_at' => now()]);

        return ['thread' => $message->thread?->fresh(), 'message' => $message->fresh()];
    }

    private function assertMessageOwnership(User $user, AssistantMessage $message): void
    {
        if ((int) $message->tenant_id !== (int) $user->tenant_id || (int) $message->user_id !== (int) $user->id) {
            abort(404);
        }
    }

    private function localized(User $user, string $ar, string $en): string
    {
        return ($user->locale ?? 'ar') === 'en' ? $en : $ar;
    }

    private function startsFreshRequest(string $message): bool
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);

        if ($normalized === '') {
            return false;
        }

        return preg_match(
            '/^(?:(?:أريد|اريد|لو سمحت|please)\s+)?(?:'
            .'أنشئ|انشئ|انشأ|أضف|اضف|ابحث|اعرض|أظهر|اظهر|اطبع|احذف|حدث|حدّث|عدل|عدّل|سجل|سجّل|شغل|شغّل|'
            .'create|add|search|find|show|print|delete|update|run'
            .')\s+(?:(?:عن|على|الى|إلى|for)\s+)?(?:'
            .'عميل|العميل|مورد|المورد|منتج|المنتج|طلب|الطلب|عقد|العقد|دفعة|الدفعة|فاتورة|الفاتورة|'
            .'صندوق|الصندوق|فرع|الفرع|مستخدم|المستخدم|تقرير|التقرير|مصروف|المصروف|كشف|supplier|customer|'
            .'product|order|contract|payment|invoice|cashbox|branch|user|report|expense'
            .')/iu',
            $normalized
        ) === 1;
    }

    private function attachRequestContext(array $plan, string $message): array
    {
        $arguments = is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [];
        $arguments['request_text'] = $arguments['request_text'] ?? $message;
        $arguments['latest_reply_text'] = $message;
        $plan['arguments'] = $arguments;

        return $plan;
    }

    private function clarificationPayloadFromPlan(array $plan): array
    {
        $payload = [];

        if (isset($plan['clarification']) && is_array($plan['clarification'])) {
            $payload['clarification'] = $plan['clarification'];
        }

        return $payload;
    }

    private function applyClarificationSelection(AssistantMessage $latestMessage, array $plan, string $message): array
    {
        $clarification = $latestMessage->execution_result_json['data']['clarification']
            ?? $plan['clarification']
            ?? null;

        if (! is_array($clarification) || ! is_array($clarification['options'] ?? null)) {
            return $plan;
        }

        $selectedOption = $this->resolveClarificationOption($clarification['options'], $message);
        if ($selectedOption === null) {
            return $plan;
        }

        $field = $clarification['field'] ?? 'target';
        $value = $selectedOption['value'] ?? null;

        if ($field === 'target') {
            $plan['target'] = is_scalar($value) ? (string) $value : null;
        } elseif (is_string($field) && str_starts_with($field, 'arguments.')) {
            $argumentKey = Str::after($field, 'arguments.');
            $arguments = is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [];
            $arguments[$argumentKey] = $value;
            $plan['arguments'] = $arguments;
        }

        unset($plan['clarification']);
        $plan['needs_clarification'] = false;
        $plan['clarification_question'] = null;

        return $plan;
    }

    private function resolveClarificationOption(array $options, string $message): ?array
    {
        $normalized = $this->normalizeChoiceInput($message);
        if ($normalized === '') {
            return null;
        }

        foreach ($options as $option) {
            $number = isset($option['number']) ? (string) $option['number'] : null;
            $value = isset($option['value']) && is_scalar($option['value']) ? trim((string) $option['value']) : null;
            $label = isset($option['label']) && is_string($option['label']) ? trim($option['label']) : null;

            if ($number !== null && $normalized === $number) {
                return $option;
            }

            if ($value !== null && $normalized === $value) {
                return $option;
            }

            if ($label !== null && mb_strtolower($normalized, 'UTF-8') === mb_strtolower($label, 'UTF-8')) {
                return $option;
            }
        }

        return null;
    }

    private function normalizeChoiceInput(string $message): string
    {
        $normalized = trim($message);
        if ($normalized === '') {
            return '';
        }

        $normalized = strtr($normalized, [
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ]);

        if (preg_match('/\b([0-9]{1,2})\b/u', $normalized, $matches) === 1) {
            return $matches[1];
        }

        return trim($normalized);
    }

    private function continuePendingClarification(AssistantThread $thread, string $message): ?array
    {
        if ($this->startsFreshRequest($message)) {
            return null;
        }

        $latestMessage = $thread->messages()->latest()->first();

        if (! $latestMessage || $latestMessage->status !== 'needs_clarification') {
            return null;
        }

        $plan = $latestMessage->planned_action_json;
        if (! is_array($plan)) {
            return null;
        }

        $plan = $this->applyClarificationSelection($latestMessage, $plan, $message);

        return match (($plan['module'] ?? '').':'.($plan['operation'] ?? '')) {
            'customers:create' => $this->continueCustomerCreateClarification($plan, $message),
            'products:create' => $this->continueProductCreateClarification($plan, $message),
            'suppliers:create' => $this->continueCreateClarification($plan, $message, 'suppliers', [$this, 'extractSupplierCreateArguments'], [
                'name' => 'ما هو اسم المورد؟',
            ]),
            'expenses:create' => $this->continueCreateClarification($plan, $message, 'expenses', [$this, 'extractExpenseCreateArguments'], [
                'branch_id|branch_name|branch' => 'حدد الفرع الخاص بالمصروف.',
                'category' => 'ما هو تصنيف المصروف؟',
                'amount' => 'ما هو مبلغ المصروف؟',
            ]),
            'payments:create' => $this->continueCreateClarification($plan, $message, 'payments', [$this, 'extractPaymentCreateArguments'], [
                'contract_id|contract' => 'حدد العقد الذي تريد تسجيل الدفعة عليه.',
                'amount' => 'ما هو مبلغ الدفعة؟',
            ]),
            'contracts:create' => $this->continueCreateClarification($plan, $message, 'contracts', [$this, 'extractContractCreateArguments'], [
                'order_id|order' => 'حدد الطلب الذي تريد تحويله إلى عقد.',
                'down_payment' => 'ما هي الدفعة الأولى للعقد؟',
                'duration_months|duration' => 'ما هي مدة العقد بالأشهر؟',
                'first_due_date' => 'ما هو تاريخ أول استحقاق؟',
            ]),
            'cashboxes:create' => $this->continueCreateClarification($plan, $message, 'cashboxes', [$this, 'extractCashboxCreateArguments'], [
                'branch_id|branch_name|branch' => 'حدد الفرع الذي تريد إنشاء الصندوق له.',
                'name' => 'ما هو اسم الصندوق؟',
            ]),
            'purchases:create' => $this->continueCreateClarification($plan, $message, 'purchases', [$this, 'extractPurchaseCreateArguments'], [
                'supplier_id|supplier|supplier_name' => 'حدد المورد الخاص بأمر الشراء.',
                'branch_id|branch_name|branch' => 'حدد الفرع الخاص بأمر الشراء.',
            ]),
            'customers:print',
            'contracts:print',
            'payments:print',
            'invoices:print',
            'purchases:print',
            'cash_transactions:print' => $this->continuePrintClarification($plan, $message),
            default => null,
        };
    }

    private function hydrateClarificationPlan(AssistantThread $thread, string $message, array $plan): array
    {
        return match (($plan['module'] ?? '').':'.($plan['operation'] ?? '')) {
            'customers:create' => $this->hydrateCustomerCreatePlan($thread, $message, $plan),
            'products:create' => $this->hydrateProductCreatePlan($thread, $message, $plan),
            'suppliers:create' => $this->hydrateCreatePlan($thread, $message, $plan, 'suppliers', [$this, 'extractSupplierCreateArguments'], [
                'name' => 'ما هو اسم المورد؟',
            ]),
            'expenses:create' => $this->hydrateCreatePlan($thread, $message, $plan, 'expenses', [$this, 'extractExpenseCreateArguments'], [
                'branch_id|branch_name|branch' => 'حدد الفرع الخاص بالمصروف.',
                'category' => 'ما هو تصنيف المصروف؟',
                'amount' => 'ما هو مبلغ المصروف؟',
            ]),
            'payments:create' => $this->hydrateCreatePlan($thread, $message, $plan, 'payments', [$this, 'extractPaymentCreateArguments'], [
                'contract_id|contract' => 'حدد العقد الذي تريد تسجيل الدفعة عليه.',
                'amount' => 'ما هو مبلغ الدفعة؟',
            ]),
            'contracts:create' => $this->hydrateCreatePlan($thread, $message, $plan, 'contracts', [$this, 'extractContractCreateArguments'], [
                'order_id|order' => 'حدد الطلب الذي تريد تحويله إلى عقد.',
                'down_payment' => 'ما هي الدفعة الأولى للعقد؟',
                'duration_months|duration' => 'ما هي مدة العقد بالأشهر؟',
                'first_due_date' => 'ما هو تاريخ أول استحقاق؟',
            ]),
            'cashboxes:create' => $this->hydrateCreatePlan($thread, $message, $plan, 'cashboxes', [$this, 'extractCashboxCreateArguments'], [
                'branch_id|branch_name|branch' => 'حدد الفرع الذي تريد إنشاء الصندوق له.',
                'name' => 'ما هو اسم الصندوق؟',
            ]),
            'purchases:create' => $this->hydrateCreatePlan($thread, $message, $plan, 'purchases', [$this, 'extractPurchaseCreateArguments'], [
                'supplier_id|supplier|supplier_name' => 'حدد المورد الخاص بأمر الشراء.',
                'branch_id|branch_name|branch' => 'حدد الفرع الخاص بأمر الشراء.',
            ]),
            'customers:print',
            'contracts:print',
            'payments:print',
            'invoices:print',
            'purchases:print',
            'cash_transactions:print' => $this->hydratePrintPlan($thread, $message, $plan),
            default => $plan,
        };
    }

    private function hydrateCustomerCreatePlan(AssistantThread $thread, string $message, array $plan): array
    {
        $previousPlan = $thread->messages()
            ->where('status', 'needs_clarification')
            ->whereNotNull('planned_action_json')
            ->latest()
            ->value('planned_action_json');

        $basePlan = is_array($previousPlan)
            && ($previousPlan['module'] ?? null) === 'customers'
            && ($previousPlan['operation'] ?? null) === 'create'
            ? $previousPlan
            : $plan;

        $arguments = array_merge(
            is_array($basePlan['arguments'] ?? null) ? $basePlan['arguments'] : [],
            is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [],
            $this->extractCustomerCreateArguments($message)
        );

        $plan['arguments'] = $arguments;

        if (! empty($arguments['name']) && ! empty($arguments['phone'])) {
            $plan['needs_clarification'] = false;
            $plan['clarification_question'] = null;
        }

        return $plan;
    }

    private function hydrateProductCreatePlan(AssistantThread $thread, string $message, array $plan): array
    {
        $previousPlan = $thread->messages()
            ->where('status', 'needs_clarification')
            ->whereNotNull('planned_action_json')
            ->latest()
            ->value('planned_action_json');

        $basePlan = is_array($previousPlan)
            && ($previousPlan['module'] ?? null) === 'products'
            && ($previousPlan['operation'] ?? null) === 'create'
            ? $previousPlan
            : $plan;

        $arguments = array_merge(
            is_array($basePlan['arguments'] ?? null) ? $basePlan['arguments'] : [],
            is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [],
            $this->extractProductCreateArguments($message)
        );

        $plan['arguments'] = $arguments;

        if (! empty($arguments['name'])) {
            $plan['needs_clarification'] = false;
            $plan['clarification_question'] = null;
        }

        return $plan;
    }

    private function continueCustomerCreateClarification(array $plan, string $message): array
    {
        $arguments = array_merge(
            is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [],
            $this->extractCustomerCreateArguments($message)
        );

        $clarificationQuestion = null;
        if (empty($arguments['name'])) {
            $clarificationQuestion = 'ما هو اسم العميل بالكامل؟';
        } elseif (empty($arguments['phone'])) {
            $clarificationQuestion = 'ما هو رقم هاتف العميل؟';
        }

        $plan['intent'] = 'create';
        $plan['module'] = 'customers';
        $plan['operation'] = 'create';
        $plan['target'] = $plan['target'] ?? null;
        $plan['arguments'] = $arguments;
        $plan['needs_clarification'] = $clarificationQuestion !== null;
        $plan['clarification_question'] = $clarificationQuestion;
        $plan['requires_delete_confirmation'] = false;

        return $plan;
    }

    private function continueProductCreateClarification(array $plan, string $message): array
    {
        $arguments = array_merge(
            is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [],
            $this->extractProductCreateArguments($message)
        );

        $clarificationQuestion = empty($arguments['name'])
            ? 'ما هو اسم المنتج؟'
            : null;

        $plan['intent'] = 'create';
        $plan['module'] = 'products';
        $plan['operation'] = 'create';
        $plan['target'] = $plan['target'] ?? null;
        $plan['arguments'] = $arguments;
        $plan['needs_clarification'] = $clarificationQuestion !== null;
        $plan['clarification_question'] = $clarificationQuestion;
        $plan['requires_delete_confirmation'] = false;

        return $plan;
    }

    private function hydrateCreatePlan(
        AssistantThread $thread,
        string $message,
        array $plan,
        string $module,
        callable $extractor,
        array $requiredQuestions
    ): array {
        $previousPlan = $thread->messages()
            ->where('status', 'needs_clarification')
            ->whereNotNull('planned_action_json')
            ->latest()
            ->value('planned_action_json');

        $basePlan = is_array($previousPlan)
            && ($previousPlan['module'] ?? null) === $module
            && ($previousPlan['operation'] ?? null) === 'create'
            ? $previousPlan
            : $plan;

        $arguments = array_merge(
            is_array($basePlan['arguments'] ?? null) ? $basePlan['arguments'] : [],
            is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [],
            $extractor($message)
        );

        $plan['intent'] = 'create';
        $plan['module'] = $module;
        $plan['operation'] = 'create';
        $plan['arguments'] = $arguments;
        $plan['requires_delete_confirmation'] = false;
        $plan['clarification_question'] = $this->nextMissingQuestion($arguments, $requiredQuestions);
        $plan['needs_clarification'] = $plan['clarification_question'] !== null;

        return $plan;
    }

    private function continueCreateClarification(array $plan, string $message, string $module, callable $extractor, array $requiredQuestions): array
    {
        $arguments = array_merge(
            is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [],
            $extractor($message)
        );

        $plan['intent'] = 'create';
        $plan['module'] = $module;
        $plan['operation'] = 'create';
        $plan['target'] = $plan['target'] ?? null;
        $plan['arguments'] = $arguments;
        $plan['clarification_question'] = $this->nextMissingQuestion($arguments, $requiredQuestions);
        $plan['needs_clarification'] = $plan['clarification_question'] !== null;
        $plan['requires_delete_confirmation'] = false;

        return $plan;
    }

    private function hydratePrintPlan(AssistantThread $thread, string $message, array $plan): array
    {
        $previousPlan = $thread->messages()
            ->where('status', 'needs_clarification')
            ->whereNotNull('planned_action_json')
            ->latest()
            ->value('planned_action_json');

        $module = (string) ($plan['module'] ?? '');
        $basePlan = is_array($previousPlan)
            && ($previousPlan['module'] ?? null) === $module
            && ($previousPlan['operation'] ?? null) === 'print'
            ? $previousPlan
            : $plan;

        $target = $plan['target'] ?? $basePlan['target'] ?? null;
        if (! is_string($target) || trim($target) === '') {
            $target = $this->extractPrintTarget($message, $module);
        }

        $plan['intent'] = 'print';
        $plan['module'] = $module;
        $plan['operation'] = 'print';
        $plan['target'] = is_string($target) ? trim($target) : null;
        $plan['requires_delete_confirmation'] = false;
        $plan['clarification_question'] = blank($plan['target']) ? $this->printClarificationQuestion($module) : null;
        $plan['needs_clarification'] = $plan['clarification_question'] !== null;

        return $plan;
    }

    private function continuePrintClarification(array $plan, string $message): array
    {
        $module = (string) ($plan['module'] ?? '');
        $target = $plan['target'] ?? null;
        if (! is_string($target) || trim($target) === '') {
            $target = $this->extractPrintTarget($message, $module);
        }

        $plan['intent'] = 'print';
        $plan['module'] = $module;
        $plan['operation'] = 'print';
        $plan['target'] = is_string($target) ? trim($target) : null;
        $plan['needs_clarification'] = blank($plan['target']);
        $plan['clarification_question'] = $plan['needs_clarification'] ? $this->printClarificationQuestion($module) : null;
        $plan['requires_delete_confirmation'] = false;

        return $plan;
    }

    private function nextMissingQuestion(array $arguments, array $requiredQuestions): ?string
    {
        foreach ($requiredQuestions as $keys => $question) {
            $alternatives = explode('|', $keys);
            $resolved = false;
            foreach ($alternatives as $key) {
                $value = $arguments[$key] ?? null;
                if ($value === null) {
                    continue;
                }

                if (is_string($value) && trim($value) === '') {
                    continue;
                }

                $resolved = true;
                break;
            }

            if ($resolved) {
                continue;
            }

            return $question;
        }

        return null;
    }

    private function printClarificationQuestion(string $module): string
    {
        return match ($module) {
            'customers' => 'حدد العميل الذي تريد طباعة كشف حسابه.',
            'contracts' => 'حدد العقد الذي تريد طباعته.',
            'payments' => 'حدد الدفعة أو الإيصال الذي تريد طباعته.',
            'invoices' => 'حدد الفاتورة التي تريد طباعتها.',
            'purchases' => 'حدد أمر الشراء الذي تريد طباعته.',
            'cash_transactions' => 'حدد حركة الصندوق أو رقم السند الذي تريد طباعته.',
            default => 'حدد السجل الذي تريد طباعته.',
        };
    }

    private function extractCustomerCreateArguments(string $message): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);
        $arguments = [];

        $labelMap = [
            'name' => [
                '/اسم(?: العميل)?(?: كامل)?\s*[:：]\s*([^\r\n]+)/u',
                '/الاسم\s*[:：]\s*([^\r\n]+)/u',
            ],
            'phone' => [
                '/رقم(?: الهاتف)?(?:ه)?\s*[:：]\s*([0-9+\-\s]{6,20})/u',
                '/الهاتف\s*[:：]\s*([0-9+\-\s]{6,20})/u',
                '/الجوال\s*[:：]\s*([0-9+\-\s]{6,20})/u',
            ],
            'branch_name' => [
                '/الفرع\s*[:：]\s*([^\r\n]+)/u',
            ],
        ];

        foreach ($labelMap as $key => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $message, $matches) === 1) {
                    $arguments[$key] = trim($matches[1]);
                    break;
                }
            }
        }

        if (! isset($arguments['name']) && preg_match('/اسم(?:ه|ها| العميل)?\s+(.+?)(?:\s+ورقم|\s+رقم|\s+في الفرع|$)/u', $normalized, $matches) === 1) {
            $arguments['name'] = $this->cleanExtractedText($matches[1]);
        }

        if (! isset($arguments['phone']) && preg_match('/رقم(?:ه|ها| هاتفه| الهاتف)?\s+([0-9+\-\s]{6,20})/u', $normalized, $matches) === 1) {
            $arguments['phone'] = preg_replace('/\s+/', '', $this->cleanExtractedText($matches[1])) ?? $this->cleanExtractedText($matches[1]);
        }

        if (! isset($arguments['branch_name']) && preg_match('/في الفرع\s+(.+?)(?:$|[،,\n\r])/u', $normalized, $matches) === 1) {
            $arguments['branch_name'] = $this->cleanExtractedText($matches[1]);
        }

        if (isset($arguments['phone'])) {
            $arguments['phone'] = preg_replace('/[^\d+]/', '', $arguments['phone']) ?? $arguments['phone'];
        }

        return array_filter($arguments, fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);
    }

    private function extractSupplierCreateArguments(string $message): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);
        $arguments = [];

        $labelMap = [
            'name' => [
                '/اسم(?: المورد)?\s*[:：]\s*([^\r\n]+)/u',
            ],
            'phone' => [
                '/رقم(?: المورد| الهاتف)?\s*[:：]?\s*([0-9+\-\s]{6,20})/u',
                '/هاتف(?: المورد)?\s*[:：]?\s*([0-9+\-\s]{6,20})/u',
            ],
            'email' => [
                '/البريد(?: الإلكتروني)?\s*[:：]?\s*([^\s\r\n]+@[^\s\r\n]+)/u',
                '/email\s*[:：]?\s*([^\s\r\n]+@[^\s\r\n]+)/iu',
            ],
            'contact_person' => [
                '/(?:المسؤول|الشخص المسؤول|جهة الاتصال)\s*[:：]\s*([^\r\n]+)/u',
            ],
            'address' => [
                '/العنوان\s*[:：]\s*([^\r\n]+)/u',
            ],
        ];

        foreach ($labelMap as $key => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $message, $matches) === 1) {
                    $arguments[$key] = $this->cleanExtractedText($matches[1]);
                    break;
                }
            }
        }

        if (! isset($arguments['name']) && preg_match('/(?:أريد|اريد)?\s*(?:إضافة|اضافة|إنشاء|انشاء|انشئ|أضف|اضف)(?:\s+مورد(?:\s+جديد)?)?\s+(?:اسمه\s+)?(.+?)(?:\s+ورقم|\s+رقم|\s+وبريد|\s+بريد|\s+وعنوان|\s+عنوان|$)/u', $normalized, $matches) === 1) {
            $arguments['name'] = $this->cleanExtractedText($matches[1]);
        }

        if (! isset($arguments['name']) && preg_match('/مورد(?:\s+اسمه)?\s+(.+?)(?:\s+ورقم|\s+رقم|\s+وبريد|\s+بريد|$)/u', $normalized, $matches) === 1) {
            $arguments['name'] = $this->cleanExtractedText($matches[1]);
        }

        if (isset($arguments['phone'])) {
            $arguments['phone'] = preg_replace('/[^\d+]/', '', (string) $arguments['phone']) ?? $arguments['phone'];
        }

        return array_filter($arguments, fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);
    }

    private function extractExpenseCreateArguments(string $message): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);
        $arguments = [];

        if (preg_match('/(?:مبلغ|بقيمة|قيمته)\s*[:：]?\s*([0-9]+(?:\.[0-9]+)?)/u', $normalized, $matches) === 1
            || preg_match('/(?:مصروف|مصاريف)\s+.+?\s+([0-9]+(?:\.[0-9]+)?)(?:\s|$)/u', $normalized, $matches) === 1
            || preg_match('/^([0-9]+(?:\.[0-9]+)?)$/u', trim($normalized), $matches) === 1) {
            $arguments['amount'] = (float) $matches[1];
        }

        if (preg_match('/تصنيف(?: المصروف)?\s*[:：]\s*([^\r\n]+)/u', $message, $matches) === 1) {
            $arguments['category'] = $this->cleanExtractedText($matches[1]);
        } elseif (preg_match('/(?:سجل|سجّل|اضف|أضف|أنشئ|انشئ)(?:\s+مصروف(?:اً|ا)?)?\s+(.+?)\s+([0-9]+(?:\.[0-9]+)?)(?:\s|$)/u', $normalized, $matches) === 1) {
            $arguments['category'] = $this->cleanExtractedText($matches[1]);
        } elseif (preg_match('/(?:مصروف|مصاريف)\s+(.+?)(?:\s+بقيمة|\s+[0-9]+(?:\.[0-9]+)?|$)/u', $normalized, $matches) === 1) {
            $arguments['category'] = $this->cleanExtractedText($matches[1]);
        }

        if (preg_match('/الفرع\s*[:：]\s*([^\r\n]+)/u', $message, $matches) === 1
            || preg_match('/(?:لفرع|في فرع)\s+(.+?)(?:$|[،,\n\r])/u', $normalized, $matches) === 1) {
            $arguments['branch_name'] = $this->cleanExtractedText($matches[1]);
        }

        if (preg_match('/(?:المورد|الجهة|Vendor)\s*[:：]\s*([^\r\n]+)/iu', $message, $matches) === 1) {
            $arguments['vendor_name'] = $this->cleanExtractedText($matches[1]);
        }

        $date = $this->extractDateValue($normalized, ['تاريخ المصروف', 'التاريخ']);
        if ($date !== null) {
            $arguments['expense_date'] = $date;
        }

        return array_filter($arguments, fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);
    }

    private function extractPaymentCreateArguments(string $message): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);
        $arguments = [];

        if (preg_match('/(?:دفعة|مبلغ|بقيمة)\s*[:：]?\s*([0-9]+(?:\.[0-9]+)?)/u', $normalized, $matches) === 1
            || preg_match('/^([0-9]+(?:\.[0-9]+)?)$/u', trim($normalized), $matches) === 1) {
            $arguments['amount'] = (float) $matches[1];
        }

        if (preg_match('/(?:على|ل(?:ل)?|لعقد)\s*عقد\s*#?\s*([A-Za-z0-9\-_]+)/u', $normalized, $matches) === 1
            || preg_match('/العقد\s*[:：]?\s*([A-Za-z0-9\-_]+)/u', $message, $matches) === 1
            || preg_match('/عقد\s*#?\s*([A-Za-z0-9\-_]+)/u', $normalized, $matches) === 1) {
            $arguments['contract'] = $this->cleanExtractedText($matches[1]);
        }

        if (preg_match('/(?:مرجع|المرجع|reference)\s*[:：]?\s*([^\r\n]+)/iu', $message, $matches) === 1) {
            $arguments['reference_number'] = $this->cleanExtractedText($matches[1]);
        }

        if (preg_match('/(?:نقد|كاش|cash)/iu', $normalized) === 1) {
            $arguments['payment_method'] = 'cash';
        } elseif (preg_match('/(?:تحويل|bank)/iu', $normalized) === 1) {
            $arguments['payment_method'] = 'bank_transfer';
        } elseif (preg_match('/(?:بطاقة|card)/iu', $normalized) === 1) {
            $arguments['payment_method'] = 'card';
        } elseif (preg_match('/(?:شيك|cheque|check)/iu', $normalized) === 1) {
            $arguments['payment_method'] = 'cheque';
        }

        $date = $this->extractDateValue($normalized, ['تاريخ الدفعة', 'التاريخ']);
        if ($date !== null) {
            $arguments['payment_date'] = $date;
        }

        return array_filter($arguments, fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);
    }

    private function extractContractCreateArguments(string $message): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);
        $arguments = [];

        if (preg_match('/(?:طلب|للطلب|الطلب)\s*#?\s*([A-Za-z0-9\-_]+)/u', $normalized, $matches) === 1) {
            $arguments['order'] = $this->cleanExtractedText($matches[1]);
        }

        if (preg_match('/دفعة(?:\s+أولى)?\s*[:：]?\s*([0-9]+(?:\.[0-9]+)?)/u', $normalized, $matches) === 1) {
            $arguments['down_payment'] = (float) $matches[1];
        }

        if (preg_match('/(?:مدة(?: العقد)?|المدة)\s*[:：]?\s*([0-9]{1,2})\s*(?:شهر|أشهر|month|months)/iu', $normalized, $matches) === 1) {
            $arguments['duration_months'] = (int) $matches[1];
        }

        $firstDueDate = $this->extractDateValue($normalized, ['أول استحقاق', 'اول استحقاق', 'تاريخ أول استحقاق']);
        if ($firstDueDate !== null) {
            $arguments['first_due_date'] = $firstDueDate;
        }

        $startDate = $this->extractDateValue($normalized, ['تاريخ البداية', 'بداية العقد', 'تاريخ العقد']);
        if ($startDate !== null) {
            $arguments['start_date'] = $startDate;
        }

        return array_filter($arguments, fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);
    }

    private function extractCashboxCreateArguments(string $message): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);
        $arguments = [];

        if (preg_match('/اسم(?: الصندوق)?\s*[:：]\s*([^\r\n]+)/u', $message, $matches) === 1) {
            $arguments['name'] = $this->cleanExtractedText($matches[1]);
        } elseif (preg_match('/(?:أنشئ|انشئ|أضف|اضف|إضافة|اضافة)\s+صندوق\s+(.+?)(?:\s+لفرع|\s+في فرع|\s+برصيد|\s+رصيد|$)/u', $normalized, $matches) === 1) {
            $arguments['name'] = $this->cleanExtractedText($matches[1]);
        }

        if (preg_match('/(?:لفرع|في فرع)\s+(.+?)(?:$|[،,\n\r])/u', $normalized, $matches) === 1
            || preg_match('/الفرع\s*[:：]\s*([^\r\n]+)/u', $message, $matches) === 1) {
            $arguments['branch_name'] = $this->cleanExtractedText($matches[1]);
        }

        if (preg_match('/(?:رصيد افتتاحي|الرصيد الافتتاحي|برصيد)\s*[:：]?\s*([0-9]+(?:\.[0-9]+)?)/u', $normalized, $matches) === 1) {
            $arguments['opening_balance'] = (float) $matches[1];
        }

        if (preg_match('/(?:رئيسي|main)/iu', $normalized) === 1) {
            $arguments['is_primary'] = true;
            $arguments['type'] = $arguments['type'] ?? 'main';
            $arguments['name'] = $arguments['name'] ?? 'الصندوق الرئيسي';
        }

        if (preg_match('/(?:فرعي|petty)/iu', $normalized) === 1) {
            $arguments['type'] = 'petty';
        }

        return array_filter($arguments, fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);
    }

    private function extractPurchaseCreateArguments(string $message): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);
        $arguments = [];

        if (preg_match('/(?:المورد|supplier)\s*[:：]\s*([^\r\n]+)/iu', $message, $matches) === 1
            || preg_match('/(?:للمورد|من المورد)\s+(.+?)(?:\s+لفرع|\s+في فرع|\s+بتاريخ|\s+موعد|\s+ملاحظ|$)/u', $normalized, $matches) === 1) {
            $arguments['supplier_name'] = $this->cleanExtractedText($matches[1]);
        }

        if (preg_match('/(?:لفرع|في فرع)\s+(.+?)(?:$|[،,\n\r])/u', $normalized, $matches) === 1
            || preg_match('/الفرع\s*[:：]\s*([^\r\n]+)/u', $message, $matches) === 1) {
            $arguments['branch_name'] = $this->cleanExtractedText($matches[1]);
        }

        $orderDate = $this->extractDateValue($normalized, ['تاريخ الطلب', 'بتاريخ']);
        if ($orderDate !== null) {
            $arguments['order_date'] = $orderDate;
        }

        $expectedDate = $this->extractDateValue($normalized, ['موعد التوريد', 'التاريخ المتوقع', 'expected']);
        if ($expectedDate !== null) {
            $arguments['expected_date'] = $expectedDate;
        }

        if (preg_match('/ملاحظ(?:ة|ات)?\s*[:：]\s*([^\r\n]+)/u', $message, $matches) === 1) {
            $arguments['notes'] = $this->cleanExtractedText($matches[1]);
        }

        return array_filter($arguments, fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);
    }

    private function extractPrintTarget(string $message, string $module): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);

        $patterns = match ($module) {
            'customers' => [
                '/(?:كشف حساب العميل|العميل)\s+(.+?)(?:\s+(?:pdf|بي\s*دي\s*اف)|$)/iu',
            ],
            'contracts' => [
                '/(?:العقد|contract)\s*#?\s*([A-Za-z0-9\-_]+)/iu',
            ],
            'payments' => [
                '/(?:الدفعة|الإيصال|ايصال|receipt|payment)\s*#?\s*([A-Za-z0-9\-_]+)/iu',
            ],
            'invoices' => [
                '/(?:الفاتورة|invoice)\s*#?\s*([A-Za-z0-9\-_]+)/iu',
            ],
            'purchases' => [
                '/(?:أمر الشراء|طلب الشراء|purchase order)\s*#?\s*([A-Za-z0-9\-_]+)/iu',
            ],
            'cash_transactions' => [
                '/(?:سند الصندوق|رقم السند|voucher)\s*#?\s*([A-Za-z0-9\-_]+)/iu',
                '/(?:حركة الصندوق|الحركة)\s*#?\s*([A-Za-z0-9\-_]+)/iu',
            ],
            default => [],
        };

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches) === 1) {
                return $this->cleanExtractedText($matches[1]);
            }
        }

        if (preg_match('/^[A-Za-z0-9\-_]+$/u', trim($normalized)) === 1) {
            return trim($normalized);
        }

        if (mb_strlen(trim($normalized)) <= 80 && ! preg_match('/(?:اطبع|طباعة|pdf|بي\s*دي\s*اف)/iu', trim($normalized))) {
            return $this->cleanExtractedText($normalized);
        }

        return null;
    }

    private function extractDateValue(string $message, array $labels = []): ?string
    {
        foreach ($labels as $label) {
            if (preg_match('/'.preg_quote($label, '/').'\s*[:：]?\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/u', $message, $matches) === 1) {
                return $matches[1];
            }
        }

        if (preg_match('/([0-9]{4}-[0-9]{2}-[0-9]{2})/u', $message, $matches) === 1) {
            return $matches[1];
        }

        $lower = mb_strtolower($message, 'UTF-8');
        return match (true) {
            Str::contains($lower, ['اليوم', 'today']) => now()->toDateString(),
            Str::contains($lower, ['غدا', 'بكرة', 'tomorrow']) => now()->addDay()->toDateString(),
            Str::contains($lower, ['أمس', 'امس', 'yesterday']) => now()->subDay()->toDateString(),
            default => null,
        };
    }

    private function extractProductCreateArguments(string $message): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);
        $arguments = [];

        $labelMap = [
            'name' => [
                '/اسم(?: المنتج)?\s*[:：]\s*([^\r\n]+)/u',
                '/المنتج\s*[:：]\s*([^\r\n]+)/u',
            ],
            'name_ar' => [
                '/الاسم العربي\s*[:：]\s*([^\r\n]+)/u',
            ],
            'cash_price' => [
                '/سعر(?: الكاش| النقدي)?\s*[:：]?\s*([0-9]+(?:\.[0-9]+)?)/u',
                '/سعره(?: كاش| نقدي)?\s*[:：]?\s*([0-9]+(?:\.[0-9]+)?)/u',
            ],
            'installment_price' => [
                '/سعر(?: التقسيط)?\s*[:：]?\s*([0-9]+(?:\.[0-9]+)?)\s*(?:تقسيط)?/u',
                '/سعر التقسيط\s*[:：]?\s*([0-9]+(?:\.[0-9]+)?)/u',
            ],
            'sku' => [
                '/sku\s*[:：]\s*([^\r\n]+)/iu',
                '/الكود\s*[:：]\s*([^\r\n]+)/u',
            ],
        ];

        foreach ($labelMap as $key => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $message, $matches) === 1) {
                    $arguments[$key] = $this->cleanExtractedText($matches[1]);
                    break;
                }
            }
        }

        if (! isset($arguments['name']) && preg_match('/(?:أريد|اريد)?\s*(?:إضافة|اضافة|إنشاء|انشاء|انشئ|أضف|اضف)(?:\s+منتج(?:\s+جديد)?)?\s+(.+?)(?:\s+بسعر|\s+سعر|\s+كاش|\s+تقسيط|$)/u', $normalized, $matches) === 1) {
            $arguments['name'] = $this->cleanExtractedText($matches[1]);
        }

        if (! isset($arguments['name']) && preg_match('/منتج(?:\s+اسمه)?\s+(.+?)(?:\s+بسعر|\s+سعر|\s+كاش|\s+تقسيط|$)/u', $normalized, $matches) === 1) {
            $arguments['name'] = $this->cleanExtractedText($matches[1]);
        }

        foreach (['cash_price', 'installment_price'] as $numericKey) {
            if (isset($arguments[$numericKey])) {
                $arguments[$numericKey] = (float) preg_replace('/[^\d.]/', '', (string) $arguments[$numericKey]);
            }
        }

        return array_filter($arguments, fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);
    }

    private function cleanExtractedText(string $value): string
    {
        $value = preg_replace('/^[\s،,]+|[\s،,]+$/u', '', $value) ?? $value;

        return $this->safeUtf8($value);
    }

    private function safeUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8, Windows-1256, ISO-8859-1');
    }

    private function executionErrorResult(User $user, Throwable $exception): array
    {
        if ($exception instanceof ValidationException) {
            $summary = $this->validationErrorSummary($user, $exception);

            return [
                'status' => 'rejected',
                'summary' => $summary,
                'data' => [
                    'error' => $summary,
                    'validation_errors' => $this->localizedValidationErrors($user, $exception),
                ],
            ];
        }

        if ($exception instanceof AuthorizationException || $this->httpStatus($exception) === 403) {
            $summary = $this->localized(
                $user,
                'ليس لديك صلاحية لتنفيذ هذا الطلب.',
                'You do not have permission to perform this request.'
            );

            return [
                'status' => 'rejected',
                'summary' => $summary,
                'data' => ['error' => $summary],
            ];
        }

        if ($exception instanceof ModelNotFoundException || $this->httpStatus($exception) === 404) {
            $summary = $this->localized(
                $user,
                'تعذر العثور على السجل المطلوب. تأكد من الرقم أو الاسم ثم حاول مرة أخرى.',
                'The requested record could not be found. Check the number or name and try again.'
            );

            return [
                'status' => 'rejected',
                'summary' => $summary,
                'data' => ['error' => $summary],
            ];
        }

        if ($exception instanceof QueryException) {
            $summary = $this->localized(
                $user,
                'تعذر تنفيذ الطلب بسبب خطأ في البيانات أو قاعدة البيانات. راجع المدخلات ثم حاول مرة أخرى.',
                'The request could not be completed because of a data or database error. Please review the inputs and try again.'
            );

            return [
                'status' => 'error',
                'summary' => $summary,
                'data' => ['error' => $summary],
            ];
        }

        return [
            'status' => 'error',
            'summary' => $this->localized(
                $user,
                'تعذر تنفيذ الطلب حالياً. راجع صياغة البيانات أو إعدادات قاعدة البيانات ثم حاول مرة أخرى.',
                'The request could not be completed right now. Please review the provided data or database settings and try again.'
            ),
            'data' => [
                'error' => $this->safeUtf8($exception->getMessage()),
            ],
        ];
    }

    private function validationErrorSummary(User $user, ValidationException $exception): string
    {
        $message = $this->firstValidationMessage($exception);
        $message = $this->localizeKnownServiceMessage($user, $message);

        return $this->localized(
            $user,
            'تعذر إكمال الطلب: '.$message,
            'Could not complete the request: '.$message
        );
    }

    private function localizedValidationErrors(User $user, ValidationException $exception): array
    {
        $localized = [];

        foreach ($exception->errors() as $field => $messages) {
            $localized[$field] = array_map(
                fn (mixed $message) => $this->localizeKnownServiceMessage($user, (string) $message),
                $messages
            );
        }

        return $localized;
    }

    private function firstValidationMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            if (is_array($messages) && isset($messages[0]) && is_string($messages[0]) && trim($messages[0]) !== '') {
                return $messages[0];
            }
        }

        return $exception->getMessage();
    }

    private function localizeKnownServiceMessage(User $user, string $message): string
    {
        $message = $this->safeUtf8(trim($message));

        if (($user->locale ?? 'ar') === 'en') {
            return $message;
        }

        if ($message === '') {
            return 'حدث خطأ غير متوقع.';
        }

        $patterns = [
            '/^Payment exceeds the remaining balance for this contract\. Maximum payable: ([0-9.]+)\.$/' => fn (array $matches) => "لا يمكن تسجيل الدفعة لأن المتبقي على العقد هو {$matches[1]} فقط.",
            '/^Payment exceeds the remaining amount for this installment\. Maximum for this line: ([0-9.]+)\.$/' => fn (array $matches) => "لا يمكن تسجيل الدفعة لأن المتبقي على هذا القسط هو {$matches[1]} فقط.",
            '/^Payment amount must be greater than zero\.$/' => fn () => 'يجب أن يكون مبلغ الدفعة أكبر من صفر.',
            '/^Unable to allocate payment across installments\. Please try again or contact support\.$/' => fn () => 'تعذر توزيع الدفعة على الأقساط. حاول مرة أخرى أو تواصل مع الدعم.',
            '/^The selected installment does not belong to this contract\.$/' => fn () => 'القسط المحدد لا يتبع هذا العقد.',
            '/^The selected installment is not valid for this contract\.$/' => fn () => 'القسط المحدد غير صالح لهذا العقد.',
            '/^Contract schedules are inconsistent\. Contact support\.$/' => fn () => 'بيانات أقساط العقد غير متسقة. يرجى التواصل مع الدعم.',
            '/^Amount must be greater than zero\.$/' => fn () => 'يجب أن يكون المبلغ أكبر من صفر.',
            '/^Invalid transaction type\.$/' => fn () => 'نوع الحركة غير صالح.',
            '/^Invalid direction\.$/' => fn () => 'اتجاه الحركة غير صالح.',
            '/^Cashbox is inactive\.$/' => fn () => 'الصندوق غير نشط.',
            '/^Supplier is inactive\.$/' => fn () => 'المورد غير نشط.',
            '/^Add at least one line to receive\.$/' => fn () => 'أضف صنفًا واحدًا على الأقل للاستلام.',
            '/^Invalid status for create\.$/' => fn () => 'الحالة غير صالحة لعملية الإنشاء.',
            '/^Invalid status\.$/' => fn () => 'الحالة غير صالحة.',
            '/^Only draft orders can be marked as ordered\.$/' => fn () => 'يمكن فقط تحويل أوامر الشراء المسودة إلى حالة تم الطلب.',
            '/^Add line items before ordering\.$/' => fn () => 'أضف أصنافًا إلى أمر الشراء قبل تحويله إلى تم الطلب.',
            '/^This purchase order cannot be cancelled\.$/' => fn () => 'لا يمكن إلغاء أمر الشراء هذا.',
            '/^Cannot cancel: goods have already been received\.$/' => fn () => 'لا يمكن الإلغاء لأنه تم استلام البضاعة بالفعل.',
            '/^Access denied\.$/' => fn () => 'ليس لديك صلاحية لتنفيذ هذا الطلب.',
        ];

        foreach ($patterns as $pattern => $translator) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return $translator($matches);
            }
        }

        return $message;
    }

    private function httpStatus(Throwable $exception): ?int
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        return null;
    }
}
