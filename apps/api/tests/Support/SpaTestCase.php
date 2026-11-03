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

        // SpaTestCase shadows RefreshDatabase::setUp(), so migrate:fresh never
        // runs automatically. Recreate migration tables explicitly so that
        // tests extending SpaTestCase + RefreshDatabase have their tables.
        $this->artisan('migrate:fresh', ['--force' => true]);

        // Exercise production error contracts independently of the local .env.
        config(['app.debug' => false, 'session.driver' => 'array', 'session.lottery' => [0, 100]]);

        // Replace only Laravel's testing bypass; retain token validation and
        // every production middleware, including any duplicate stack.
        foreach ([PreventRequestForgery::class, ValidateCsrfToken::class, VerifyCsrfToken::class] as $middleware) {
            $this->app->bind($middleware, EnforcedCsrf::class);
        }
    }
}
