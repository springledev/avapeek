<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ChildUser extends Pivot
{
    protected $table = 'child_users';

    protected $fillable = [
        'child_id',
        'user_id',
        'role',
        'relationship',
    ];

    public $timestamps = true;
}
