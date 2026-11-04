<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class SpaTestCase extends TestCase
{
    use SpaRequests;

    protected function setUp(): void
    {
        // Force array session driver before parent::setUp() boots Laravel.
        // Otherwise the database session driver is resolved during bootstrap
        // and requires a sessions table that does not exist yet.
        $_ENV['SESSION_DRIVER'] = 'array';
        $_SERVER['SESSION_DRIVER'] = 'array';
        putenv('SESSION_DRIVER=array');

        parent::setUp();

        config(['app.debug' => false, 'session.driver' => 'array', 'session.lottery' => [0, 100]]);

        if (!Schema::hasTable('sessions')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        foreach ([PreventRequestForgery::class, ValidateCsrfToken::class, VerifyCsrfToken::class] as $middleware) {
            $this->app->bind($middleware, EnforcedCsrf::class);
        }
    }
}
