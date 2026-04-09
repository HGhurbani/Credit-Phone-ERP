<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['tenant_id', 'group', 'key', 'value', 'type'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getValueAttribute($value): mixed
    {
        return match($this->type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'json' => json_decode($value, true),
            'encrypted' => $value,
            default => $value,
        };
    }

    public function setValueAttribute(mixed $value): void
    {
        $this->attributes['value'] = is_array($value) ? json_encode($value) : $value;
    }
}
