<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated hostnames, IPs or CIDR ranges allowed to set
    | X-Forwarded-For. docker-compose.prod.yml sets this to the bundled Caddy.
    | When empty nobody is trusted and the client IP is the connecting address.
    |
    */

    'proxies' => env('BACKEND_TRUSTED_PROXIES'),

];
