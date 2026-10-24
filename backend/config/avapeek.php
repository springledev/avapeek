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
    | Direct Uploads
    |--------------------------------------------------------------------------
    |
    | When true, browsers upload encrypted blobs straight to object storage via
    | presigned PUT URLs. Requires bucket CORS allowing PUT with Content-Type and
    | Cache-Control headers. When false, `photos/upload-intent` returns 404 and
    | clients fall back to the multipart `photos/upload` endpoint.
    |
    */

    'uploads' => [
        'direct' => filter_var(env('UPLOADS_DIRECT', true), FILTER_VALIDATE_BOOLEAN),
        // Live (unexpired) upload intents per user; stops one user reserving the whole quota.
        'max_pending_intents' => 20,
    ],

];
