<?php

$publicKey = env('VAPID_PUBLIC_KEY');
$privateKey = env('VAPID_PRIVATE_KEY');

return [

    /*
    |--------------------------------------------------------------------------
    | Web Push (VAPID)
    |--------------------------------------------------------------------------
    |
    | Push is active only when PUSH_ENABLED is true and both VAPID keys are set.
    | Generate keys with `php artisan avapeek:push-keys`. VAPID_SUBJECT is the
    | operator contact (mailto: or https URL), never a user's email.
    |
    */

    'enabled' => filter_var(env('PUSH_ENABLED', true), FILTER_VALIDATE_BOOLEAN) && filled($publicKey) && filled($privateKey),

    'public_key' => $publicKey,

    'private_key' => $privateKey,

    'subject' => env('VAPID_SUBJECT', 'mailto:admin@example.invalid'),

    /*
    | Seconds events are collected before one notification per user and type
    | is sent, and how long the "batch pending" marker lives in the cache.
    */
    'batch_delay_seconds' => 60,

    'batch_ttl_seconds' => 90,

    'http_timeout_seconds' => 10,

    'ttl_seconds' => 86400,

    'max_failed_attempts' => 5,

    'prune_after_days' => 60,

];
