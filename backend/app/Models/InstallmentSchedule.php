<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InstallmentSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id', 'tenant_id', 'installment_number', 'due_date',
        'amount', 'paid_amount', 'remaining_amount', 'status', 'paid_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'due_date' => 'date',
        'paid_date' => 'date',
        'installment_number' => 'integer',
    ];

    public function contract()
    {
        return $this->belongsTo(InstallmentContract::class, 'contract_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'schedule_id');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    public function scopeDueToday($query)
    {
        return $query->where('due_date', today())->whereIn('status', ['upcoming', 'due_today', 'partial']);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['upcoming', 'due_today', 'partial', 'overdue']);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'overdue';
    }
}
