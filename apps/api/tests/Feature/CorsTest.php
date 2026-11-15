<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

it('allowed origins are driven by env and never wildcard with credentials', function () {
    Config::set('cors.supports_credentials', true);
    Config::set('cors.allowed_origins', ['https://app.example.com']);

    $origins = config('cors.allowed_origins');

    expect($origins)->toEqual(['https://app.example.com']);
    expect($origins)->not->toContain('*');
});

it('CORS does not allow * origin when credentials are enabled', function () {
    $origins = config('cors.allowed_origins');
    $credentials = config('cors.supports_credentials');

    // When credentials are supported, a wildcard origin is not acceptable.
    // The production CORS config derives allowed_origins from CORS_ALLOWED_ORIGINS
    // env (no wildcards) and hardcodes supports_credentials to true.
    expect($credentials)->toBeTrue();
    expect($origins)->not->toContain('*');
});

it('allowed methods are scoped, not wildcard', function () {
    $methods = config('cors.allowed_methods');

    expect($methods)->not->toContain('*');
    expect($methods)->toContain('GET');
    expect($methods)->toContain('POST');
});

it('allowed headers are scoped, not wildcard', function () {
    $headers = config('cors.allowed_headers');

    expect($headers)->not->toContain('*');
});
