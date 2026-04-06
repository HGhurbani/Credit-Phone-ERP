<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'category_id', 'brand_id', 'name', 'name_ar', 'sku',
        'description', 'cash_price', 'installment_price', 'cost_price',
        'min_down_payment', 'allowed_durations', 'monthly_percent_of_cash', 'fixed_monthly_amount', 'image',
        'track_serial', 'is_active',
    ];

    protected $casts = [
        'cash_price' => 'decimal:2',
        'installment_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'min_down_payment' => 'decimal:2',
        'monthly_percent_of_cash' => 'decimal:2',
        'fixed_monthly_amount' => 'decimal:2',
        'allowed_durations' => 'array',
        'track_serial' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockForBranch($branchId)
    {
        return $this->inventories()->where('branch_id', $branchId)->first();
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
              ->orWhere('name_ar', 'like', "%{$search}%")
              ->orWhere('sku', 'like', "%{$search}%");
        });
    }
}
