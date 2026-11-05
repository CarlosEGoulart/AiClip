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

    protected static bool $migrationsRan = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercise production error contracts independently of the local .env.
        config(['app.debug' => false, 'session.driver' => 'database', 'session.lottery' => [0, 100]]);

        // Ensure database tables exist for tests that extend SpaTestCase.
        // Runs once per process via static flag to avoid exhausting DB connections.
        if (!self::$migrationsRan) {
            if (!Schema::hasTable('users')) {
                Artisan::call('migrate', ['--force' => true]);
            }
            self::$migrationsRan = true;
        }

        // Replace only Laravel's testing bypass; retain token validation and
        // every production middleware, including any duplicate stack.
        foreach ([PreventRequestForgery::class, ValidateCsrfToken::class, VerifyCsrfToken::class] as $middleware) {
            $this->app->bind($middleware, EnforcedCsrf::class);
        }
    }
}
