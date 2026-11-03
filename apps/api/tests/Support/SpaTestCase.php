<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

abstract class SpaTestCase extends TestCase
{
    use DatabaseTransactions;
    use SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();

        // CI runs migrations once during "Run Migrations" step, so tables already exist.
        // SpaTestCase::setUp() shadows DatabaseTransactions::setUp(), so
        // beginDatabaseTransaction() never runs automatically. Call it explicitly
        // so each test runs within a transaction that rolls back in tearDown.
        // This avoids calling migrate:fresh (refreshTestDatabase) before every test,
        // which was extremely slow and caused CI timeouts.
        $this->beginDatabaseTransaction();

        // Exercise production error contracts independently of the local .env.
        config(['app.debug' => false, 'session.driver' => 'array', 'session.lottery' => [0, 100]]);

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
