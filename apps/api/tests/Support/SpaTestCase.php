<?php

namespace Tests\Support;

use Tests\TestCase;

abstract class SpaTestCase extends TestCase
{
    use SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();

        // SpaTestCase::setUp() shadows RefreshDatabase::setUp(), so migrate:fresh
        // may not run automatically for tests that use both SpaTestCase and
        // RefreshDatabase. Call refreshTestDatabase() explicitly when the trait
        // is in use so migration tables exist for every test.
        if (method_exists(\$this, 'refreshTestDatabase')) {
            \$this->refreshTestDatabase();
        }

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
