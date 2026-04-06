<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'branch_id', 'customer_id', 'contract_id', 'schedule_id',
        'invoice_id', 'collected_by', 'receipt_number', 'amount', 'payment_method',
        'payment_date', 'reference_number', 'collector_notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function contract()
    {
        return $this->belongsTo(InstallmentContract::class, 'contract_id');
    }

    public function schedule()
    {
        return $this->belongsTo(InstallmentSchedule::class, 'schedule_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function collectedBy()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function receipt()
    {
        return $this->hasOne(Receipt::class);
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
