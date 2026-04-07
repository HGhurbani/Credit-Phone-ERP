<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashTransaction extends Model
{
    public const TYPE_CUSTOMER_PAYMENT_IN = 'customer_payment_in';

    public const TYPE_EXPENSE_OUT = 'expense_out';

    public const TYPE_MANUAL_ADJUSTMENT = 'manual_adjustment';

    public const TYPE_PURCHASE_PAYMENT_OUT = 'purchase_payment_out';

    public const TYPE_OTHER_IN = 'other_in';

    public const TYPE_OTHER_OUT = 'other_out';

    protected $fillable = [
        'tenant_id', 'cashbox_id', 'branch_id', 'transaction_type',
        'reference_type', 'reference_id', 'amount', 'direction',
        'transaction_date', 'notes', 'created_by', 'voucher_number',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
