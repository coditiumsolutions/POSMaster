<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    use HasFactory;

    protected $table = 'product_categories';
    protected $primaryKey = 'uid';
    public $timestamps = false;

    protected $fillable = [
        'category_code',
        'category_name',
        'description',
        'image_path',
        'image_name',
        'is_active',
        'created_date',
        'updated_date',
    ];
}
