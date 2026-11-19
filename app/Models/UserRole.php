<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRole extends Model
{
    protected $table = 'user_roles';
    protected $primaryKey = 'uid';
    public $timestamps = false;

    protected $fillable = [
        'user_uid',
        'role_name',
        'created_date',
    ];

    protected $casts = [
        'created_date' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_uid', 'uid');
    }
}
