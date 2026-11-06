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

        // Exercise production error contracts independently of the local .env.
        config(['app.debug' => false, 'session.driver' => 'database', 'session.lottery' => [0, 100]]);

        // Replace only Laravel's testing bypass for middleware classes that
        // routes don't directly reference. Leave VerifyCsrfToken unbound so
        // routes using VerifyCsrfToken::class get the default behavior
        // (skips CSRF during unit tests via runningUnitTests() → true).
        // Binding VerifyCsrfToken to EnforcedCsrf would force CSRF checks on
        // postJson() calls in tests, which don't send X-XSRF-TOKEN headers.
        foreach ([PreventRequestForgery::class, ValidateCsrfToken::class] as $middleware) {
            $this->app->bind($middleware, EnforcedCsrf::class);
        }
    }
}
