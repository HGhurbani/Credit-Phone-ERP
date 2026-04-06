<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Guarantor extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'name', 'phone', 'national_id',
        'relationship', 'employer_name', 'monthly_salary', 'address', 'notes',
    ];

    protected $casts = [
        'monthly_salary' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
