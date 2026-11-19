<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerMonthlySupply extends Model
{
    use HasFactory;

    protected $table = 'customer_monthly_supplies';
    protected $primaryKey = 'uid';
    public $timestamps = false;

    const CREATED_AT = 'created_date';
    const UPDATED_AT = 'updated_date';

    protected $fillable = [
        'customer_uid',
        'product_uid',
        'day_of_month',
        'quantity',
        'rate',
        'delivery_charges',
        'is_active',
        'created_date',
        'updated_date',
    ];

    protected $casts = [
        'day_of_month' => 'integer',
        'quantity' => 'decimal:3',
        'rate' => 'decimal:2',
        'delivery_charges' => 'decimal:2',
        'is_active' => 'boolean',
        'created_date' => 'datetime',
        'updated_date' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_uid', 'uid');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_uid', 'uid');
    }

    /** day_of_month 0 = every day */
    public function scopeForDate($query, $date)
    {
        $day = (int) \Carbon\Carbon::parse($date)->day;
        return $query->where(function ($q) use ($day) {
            $q->where('day_of_month', 0)->orWhere('day_of_month', $day);
        });
    }

    public function scopeActive($query)
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('customer_monthly_supplies', 'is_active')) {
            return $query->where('is_active', true);
        }
        return $query;
    }
}
