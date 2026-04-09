<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssistantMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'thread_id',
        'tenant_id',
        'user_id',
        'channel',
        'user_message',
        'assistant_message',
        'planned_action_json',
        'execution_result_json',
        'status',
        'requires_delete_confirmation',
        'confirmation_code_hash',
        'confirmation_expires_at',
        'confirmed_at',
    ];

    protected $casts = [
        'planned_action_json' => 'array',
        'execution_result_json' => 'array',
        'requires_delete_confirmation' => 'boolean',
        'confirmation_expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function thread()
    {
        return $this->belongsTo(AssistantThread::class, 'thread_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
