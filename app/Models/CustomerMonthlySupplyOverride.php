<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerMonthlySupplyOverride extends Model
{
    use HasFactory;

    protected $table = 'customer_monthly_supply_overrides';
    protected $primaryKey = 'uid';
    public $timestamps = false;

    const CREATED_AT = 'created_date';
    const UPDATED_AT = 'updated_date';

    protected $fillable = [
        'customer_uid',
        'product_uid',
        'override_date',
        'quantity',
        'rate',
        'is_skipped',
        'remarks',
        'created_date',
        'updated_date',
    ];

    protected $casts = [
        'override_date' => 'date',
        'quantity' => 'decimal:3',
        'rate' => 'decimal:2',
        'is_skipped' => 'boolean',
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
}
