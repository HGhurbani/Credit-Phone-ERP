<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'branch_id', 'created_by', 'name', 'phone', 'email',
        'national_id', 'id_type', 'address', 'city', 'employer_name',
        'monthly_salary', 'credit_score', 'notes', 'is_active',
    ];

    protected $casts = [
        'monthly_salary' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function guarantors()
    {
        return $this->hasMany(Guarantor::class);
    }

    public function documents()
    {
        return $this->hasMany(CustomerDocument::class);
    }

    public function notes()
    {
        return $this->hasMany(CustomerNote::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function contracts()
    {
        return $this->hasMany(InstallmentContract::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function collectionFollowUps()
    {
        return $this->hasMany(CollectionFollowUp::class);
    }

    public function promiseToPays()
    {
        return $this->hasMany(PromiseToPay::class);
    }

    public function rescheduleRequests()
    {
        return $this->hasMany(RescheduleRequest::class);
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('phone', 'like', "%{$search}%")
              ->orWhere('national_id', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        });
    }
}
