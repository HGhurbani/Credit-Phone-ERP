<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    public $timestamps = false;

    protected $fillable = ['payment_id', 'tenant_id', 'receipt_number', 'print_data', 'printed_at'];

    protected $casts = [
        'print_data' => 'array',
        'printed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
