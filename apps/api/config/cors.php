<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The frontend dev server runs on a different origin (5173) than the API
    | (8000) and sends credentials (cookies) with state-changing requests.
    | Browsers require Access-Control-Allow-Credentials: true together with an
    | explicit Access-Control-Allow-Origin (not '*') when credentials are used.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://127.0.0.1:5173',
        'http://localhost:5173',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'supports_credentials' => true,

    'max_age' => 0,

];
