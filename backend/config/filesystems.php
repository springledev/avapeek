<?php

/*
| Backup disk: when BACKUP_AWS_BUCKET is set, ALL backup settings come from
| BACKUP_AWS_*; otherwise ALL come from the photo (s3) settings. Never a
| per-field mix. `?:` makes empty .env values count as unset.
*/
$dedicatedBackupDisk = (bool) (env('BACKUP_AWS_BUCKET') ?: null);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'temporary_url' => env('AWS_TEMPORARY_URL') ?: null,
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // Passed to the AWS S3Client (not Flysystem options). Avoids default CRC32
            // checksums that break B2/R2/Garage.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'throw' => false,
            'report' => false,
        ],

        'backup' => [
            'driver' => 's3',
            'key' => $dedicatedBackupDisk ? env('BACKUP_AWS_ACCESS_KEY_ID') : env('AWS_ACCESS_KEY_ID'),
            'secret' => $dedicatedBackupDisk ? env('BACKUP_AWS_SECRET_ACCESS_KEY') : env('AWS_SECRET_ACCESS_KEY'),
            'region' => $dedicatedBackupDisk ? env('BACKUP_AWS_DEFAULT_REGION') : env('AWS_DEFAULT_REGION'),
            'bucket' => $dedicatedBackupDisk ? env('BACKUP_AWS_BUCKET') : env('AWS_BUCKET'),
            'endpoint' => $dedicatedBackupDisk ? (env('BACKUP_AWS_ENDPOINT') ?: null) : env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => $dedicatedBackupDisk
                ? env('BACKUP_AWS_USE_PATH_STYLE_ENDPOINT', false)
                : env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
