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

];
