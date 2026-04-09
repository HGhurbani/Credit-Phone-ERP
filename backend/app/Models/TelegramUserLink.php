<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramUserLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'telegram_user_id',
        'telegram_chat_id',
        'telegram_username',
        'linked_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
