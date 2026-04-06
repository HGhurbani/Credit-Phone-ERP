<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class TenantSettings
{
    /** قيمة خام من جدول settings (بدون cast) */
    public static function raw(int $tenantId, string $key, ?string $default = null): ?string
    {
        $v = DB::table('settings')
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->value('value');

        return $v !== null ? (string) $v : $default;
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
}
