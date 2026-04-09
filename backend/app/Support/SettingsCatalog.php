<?php

namespace App\Support;

class SettingsCatalog
{
    public const SECRET_KEYS = [
        'assistant_openai_api_key',
        'assistant_gemini_api_key',
        'telegram_bot_token',
        'telegram_webhook_secret',
    ];

    public const BOOLEAN_KEYS = [
        'show_logo_on_invoice',
        'assistant_enabled',
        'telegram_enabled',
    ];

    public const INTEGER_KEYS = [
        'grace_days',
    ];

    public const ASSISTANT_MUTABLE_KEYS = [
        'assistant_enabled',
        'assistant_provider',
        'assistant_openai_model',
        'assistant_openai_api_key',
        'assistant_gemini_model',
        'assistant_gemini_api_key',
        'telegram_enabled',
        'telegram_bot_token',
        'telegram_webhook_secret',
    ];

    public static function groupForKey(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'assistant_') => 'assistant',
            str_starts_with($key, 'telegram_') => 'telegram',
            str_starts_with($key, 'invoice_') || $key === 'show_logo_on_invoice' => 'invoice',
            str_starts_with($key, 'installment_') || in_array($key, ['admin_fee_percentage', 'late_fee_percentage', 'grace_days'], true) => 'installment',
            default => 'company',
        };
    }

    public static function typeForKey(string $key): string
    {
        if (in_array($key, self::SECRET_KEYS, true)) {
            return 'encrypted';
        }

        if (in_array($key, self::BOOLEAN_KEYS, true)) {
            return 'boolean';
        }

        if (in_array($key, self::INTEGER_KEYS, true)) {
            return 'integer';
        }

        return 'string';
    }

    public static function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    public static function isAssistantSetting(string $key): bool
    {
        return in_array($key, self::ASSISTANT_MUTABLE_KEYS, true);
    }
}
