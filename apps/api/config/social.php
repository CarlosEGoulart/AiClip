<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Social Platform Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for each social platform provider. Each provider must
    | implement the SocialConnectionProvider interface.
    |
    */

    'providers' => [
        'youtube' => [
            'client_id' => env('YOUTUBE_CLIENT_ID'),
            'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
            'redirect_uri' => env('APP_URL').'/api/v1/social/youtube/callback',
            'scopes' => ['youtube.upload', 'youtube.readonly'],
        ],
        'instagram' => [
            'client_id' => env('INSTAGRAM_CLIENT_ID'),
            'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
            'redirect_uri' => env('APP_URL').'/api/v1/social/instagram/callback',
            'scopes' => ['instagram_content_publish', 'instagram_basic'],
        ],
        'tiktok' => [
            'client_id' => env('TIKTOK_CLIENT_ID'),
            'client_secret' => env('TIKTOK_CLIENT_SECRET'),
            'redirect_uri' => env('APP_URL').'/api/v1/social/tiktok/callback',
            'scopes' => ['video.publish', 'user.info.basic'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Platforms
    |--------------------------------------------------------------------------
    |
    | List of supported platform identifiers. Used for validation.
    |
    */

    'platforms' => ['youtube', 'instagram', 'tiktok'],

    /*
    |--------------------------------------------------------------------------
    | OAuth State Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for OAuth state parameter (CSRF protection).
    |
    */

    'oauth_state' => [
        'ttl_seconds' => 600, // 10 minutes
        'length' => 40,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limit configuration for social connection endpoints.
    |
    */

    'rate_limits' => [
        'connect' => 20,      // per minute
        'list' => 60,         // per minute
        'disconnect' => 30,   // per minute
    ],

];
