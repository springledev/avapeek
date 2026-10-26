<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ChildUser extends Pivot
{
    protected $table = 'child_users';

    public $timestamps = false;

    protected $fillable = [
        'child_id',
        'user_id',
        'role',
        'relationship',
        'can_upload',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'can_upload' => 'boolean',
        ];
    }
}
