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
        parent::setUp();

        config(['app.debug' => false, 'session.driver' => 'array', 'session.lottery' => [0, 100]]);

        if (!Schema::hasTable('social_publish_campaigns')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        foreach ([PreventRequestForgery::class, ValidateCsrfToken::class, VerifyCsrfToken::class] as $middleware) {
            $this->app->bind($middleware, EnforcedCsrf::class);
        }
    }
