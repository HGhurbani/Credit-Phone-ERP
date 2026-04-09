<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TenantSettings
{
    /** قيمة خام من جدول settings (بدون cast) */
    public static function raw(int $tenantId, string $key, ?string $default = null): ?string
    {
        $row = DB::table('settings')
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->select('value', 'type')
            ->first();

        if ($row === null || $row->value === null) {
            return $default;
        }

        if ($row->type === 'encrypted') {
            try {
                return Crypt::decryptString((string) $row->value);
            } catch (\Throwable) {
                return $default;
            }
        }

        return (string) $row->value;
    }

    public static function string(int $tenantId, string $key, string $default = ''): string
    {
        return self::raw($tenantId, $key, $default) ?? $default;
    }

    public static function float(int $tenantId, string $key, float $default = 0.0): float
    {
        $v = self::raw($tenantId, $key, null);

        return $v !== null && $v !== '' ? (float) $v : $default;
    }

    public static function bool(int $tenantId, string $key, bool $default = false): bool
    {
        $v = self::raw($tenantId, $key, null);

        if ($v === null || $v === '') {
            return $default;
        }

        return filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function has(int $tenantId, string $key): bool
    {
        return DB::table('settings')
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->whereNotNull('value')
            ->exists();
    }
}
