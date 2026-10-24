<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage Quota
    |--------------------------------------------------------------------------
    |
    | limit_bytes: hard storage limit in bytes. When null, the limit is derived
    | from the free space at volume_path (self-hosted Garage). When neither is
    | set (external S3 providers) the limit is unknown and uploads are not
    | capped by the application.
    |
    */

    'storage' => [
        'limit_bytes' => env('STORAGE_LIMIT_BYTES') !== null && env('STORAGE_LIMIT_BYTES') !== ''
            ? (int) env('STORAGE_LIMIT_BYTES')
            : null,
        'volume_path' => env('STORAGE_VOLUME_PATH') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Backups
    |--------------------------------------------------------------------------
    |
    | retention_days: dumps older than this are pruned after a successful
    | upload; 0 disables app-side deletes. keep_minimum: the newest N dumps are
    | always kept. age_recipient: optional PUBLIC age recipient (age1...); the
    | matching identity must never be stored on the server.
    |
    */

    'backup' => [
        'retention_days' => env('BACKUP_RETENTION_DAYS') !== null && env('BACKUP_RETENTION_DAYS') !== ''
            ? max(0, (int) env('BACKUP_RETENTION_DAYS'))
            : 14,
        'keep_minimum' => 3,
        // Database connection to dump; null means the default connection.
        'connection' => null,
        'age_recipient' => env('BACKUP_AGE_RECIPIENT') ?: null,
        'prefix' => 'backups/db',
    ],

];
