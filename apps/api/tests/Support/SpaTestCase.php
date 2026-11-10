<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Tests\TestCase;

abstract class SpaTestCase extends TestCase
{
    use SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();

        // Replace only Laravel's testing bypass; retain token validation.
        foreach ([PreventRequestForgery::class, ValidateCsrfToken::class, VerifyCsrfToken::class] as \$middleware) {
            \$this->app->bind(\$middleware, EnforcedCsrf::class);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
