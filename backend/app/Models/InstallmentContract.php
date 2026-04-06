<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstallmentContract extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'branch_id', 'customer_id', 'order_id', 'created_by',
        'contract_number', 'financed_amount', 'down_payment', 'duration_months',
        'monthly_amount', 'total_amount', 'paid_amount', 'remaining_amount',
        'start_date', 'first_due_date', 'end_date', 'status', 'notes',
    ];

    protected $casts = [
        'financed_amount' => 'decimal:2',
        'down_payment' => 'decimal:2',
        'monthly_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'start_date' => 'date',
        'first_due_date' => 'date',
        'end_date' => 'date',
        'duration_months' => 'integer',
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

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function schedules()
    {
        return $this->hasMany(InstallmentSchedule::class, 'contract_id')->orderBy('installment_number');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'contract_id');
    }

    public function collectionLogs()
    {
        return $this->hasMany(CollectionLog::class, 'contract_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'contract_id');
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    public function isFullyPaid(): bool
    {
        return $this->remaining_amount <= 0;
    }
}
