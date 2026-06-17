<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageUsage extends Model
{
    protected $table = 'storage_usage';

    public $timestamps = false;

    protected $fillable = [
        'key',
        'used_bytes',
    ];

    public static function global(): self
    {
        return self::firstOrCreate(
            ['key' => 'global'],
            ['used_bytes' => 0],
        );
    }
}
