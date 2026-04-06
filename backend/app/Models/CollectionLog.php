<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'contract_id', 'customer_id', 'payment_id',
        'logged_by', 'action', 'notes', 'follow_up_date',
    ];

    protected $casts = [
        'follow_up_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract()
    {
        return $this->belongsTo(InstallmentContract::class, 'contract_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function loggedBy()
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
