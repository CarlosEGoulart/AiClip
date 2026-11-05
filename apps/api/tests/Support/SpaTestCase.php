<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class SpaTestCase extends TestCase
{
    use SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercise production error contracts independently of the local .env.
        config(['app.debug' => false, 'session.driver' => 'database', 'session.lottery' => [0, 100]]);

        // Ensure database tables exist for tests that extend SpaTestCase.
        // RefreshDatabase::setUp() is shadowed by TestCase::setUp(), so run
        // migrations here if the users table is missing.
        if (!Schema::hasTable('users')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        // Replace only Laravel's testing bypass; retain token validation and
        // every production middleware, including any duplicate stack.
        foreach ([PreventRequestForgery::class, ValidateCsrfToken::class, VerifyCsrfToken::class] as $middleware) {
            $this->app->bind($middleware, EnforcedCsrf::class);
        }
    }
}
