<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| CORS is deliberately restrictive here.
|
| In the intended setup the browser never calls this API directly at all - the
| Next.js BFF route calls it server-to-server, and server-to-server requests
| ignore CORS entirely. So a permissive '*' would buy nothing and would let any
| website on the internet make authenticated requests on a visitor's behalf.
|
| The single allowed origin exists so the frontend can be pointed straight at
| this API during local debugging without editing config.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    // Exactly one origin, from the environment. Never '*'.
    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    // Headers a browser client is allowed to read. X-Cache is exposed so the
    // caching behaviour is visible in devtools during a demo.
    'exposed_headers' => ['X-Cache', 'ETag'],

    'max_age' => 3600,

    // No cookies are used - authentication is a bearer token - so credentialed
    // requests are not permitted.
    'supports_credentials' => false,

];
