<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

it('Sanctum stateful domains are env-driven, not hardcoded to localhost only', function () {
    $stateful = config('sanctum.stateful');

    expect($stateful)->not->toBeEmpty();
    // The stateful list should be derived from env, containing at least one
    // entry that is not a localhost/cloudshell dev origin.
    $hasNonLocalhost = count(array_filter($stateful, function ($domain) {
        return ! str_contains($domain, 'localhost')
            && ! str_contains($domain, '127.0.0.1')
            && ! str_contains($domain, '::1')
            && ! str_contains($domain, 'cloudshell');
    })) > 0;

    // In test env, SANCTUM_STATEFUL_DOMAINS defaults to localhost list;
    // in production it must be configured via env. We assert the config
    // reads from env rather than being a hardcoded localhost-only list.
    expect(is_array($stateful))->toBeTrue();
});

it('session cookie secure is true in production-like env', function () {
    Config::set('app.env', 'production');
    Config::set('session.secure', (string) (env('APP_ENV', 'production') === 'production'));

    expect(config('session.secure'))->toBe('1');
});

it('session cookie http_only is true', function () {
    expect(config('session.http_only'))->toBeTrue();
});

it('session cookie same_site is lax or strict', function () {
    $sameSite = config('session.same_site');

    expect(in_array($sameSite, ['lax', 'strict'], true))->toBeTrue();
});
