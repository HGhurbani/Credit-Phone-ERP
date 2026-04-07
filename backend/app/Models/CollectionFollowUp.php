<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionFollowUp extends Model
{
    protected $fillable = [
        'tenant_id', 'branch_id', 'customer_id', 'contract_id',
        'outcome', 'next_follow_up_date', 'priority', 'note', 'created_by',
    ];

    protected $casts = [
        'next_follow_up_date' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(InstallmentContract::class, 'contract_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
