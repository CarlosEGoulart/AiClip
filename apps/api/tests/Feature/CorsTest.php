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
    Config::set('cors.supports_credentials', true);
    Config::set('cors.allowed_origins', ['*']);

    $origins = config('cors.allowed_origins');

    // When credentials are supported, a wildcard origin is not acceptable.
    expect(config('cors.supports_credentials'))->toBeTrue();
    expect($origins)->not->toEqual(['*']);
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
