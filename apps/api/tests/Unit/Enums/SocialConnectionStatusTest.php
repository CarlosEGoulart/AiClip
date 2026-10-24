<?php

namespace Tests\Unit\Enums;

use App\Enums\SocialConnectionStatus;
use Tests\TestCase;

uses(TestCase::class);

it('defines connected status', function () {
    expect(SocialConnectionStatus::Connected->value)->toBe('connected');
});

it('defines expired status', function () {
    expect(SocialConnectionStatus::Expired->value)->toBe('expired');
});

it('defines revoked status', function () {
    expect(SocialConnectionStatus::Revoked->value)->toBe('revoked');
});

it('defines error status', function () {
    expect(SocialConnectionStatus::Error->value)->toBe('error');
});
