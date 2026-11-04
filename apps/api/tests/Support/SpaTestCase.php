<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class SpaTestCase extends TestCase
{
    use DatabaseTransactions;
    use SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false, 'session.driver' => 'array', 'session.lottery' => [0, 100]]);

        if (!Schema::hasTable('sessions')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        // Replace only Laravel's testing bypass; retain token validation and
        // every production middleware, including any duplicate stack.
        foreach ([PreventRequestForgery::class, ValidateCsrfToken::class, VerifyCsrfToken::class] as $middleware) {
            $this->app->bind($middleware, EnforcedCsrf::class);
        }
    }

    protected function tearDown(): void
    {
        $this->rollBackTransaction();

        parent::tearDown();
    }
}
