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
    | In production, allowed origins are driven by the CORS_ALLOWED_ORIGINS
    | environment variable (comma-separated). A wildcard origin is never
    | permitted when supports_credentials is true.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => (function (): array {
        $origins = explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''));
        $origins = array_map('trim', $origins);
        $origins = array_filter($origins);

        return array_values($origins);
    })(),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With', 'X-XSRF-TOKEN'],

    'exposed_headers' => [],

    'supports_credentials' => true,

    'max_age' => 0,

];
