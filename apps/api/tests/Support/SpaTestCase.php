<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

abstract class SpaTestCase extends TestCase
{
    use SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercise production error contracts independently of the local .env.
        // Migration tables are created by CI's "Run Migrations" step before
        // tests execute. SpaTestCase::setUp() shadows RefreshDatabase::setUp(),
        // so migrate:fresh does not run automatically here.
        config(['app.debug' => false, 'session.driver' => 'array', 'session.lottery' => [0, 100]]);

        // Replace only Laravel's testing bypass; retain token validation and
        // every production middleware, including any duplicate stack.
        foreach ([PreventRequestForgery::class, ValidateCsrfToken::class, VerifyCsrfToken::class] as \$middleware) {
            \$this->app->bind(\$middleware, EnforcedCsrf::class);
        }
    }

    protected function tearDown(): void
    {
        // Clear rate limiters registered by AppServiceProvider to prevent
        // cross-test accumulation. CACHE_STORE=array means the array cache
        // store persists across tests in the same PHP process, so rate limit
        // counters accumulate and can cause 429 responses in subsequent tests.
        RateLimiter::clear('auth-register');
        RateLimiter::clear('auth-login');
        RateLimiter::clear('auth-logout');
        RateLimiter::clear('project-destroy');
        RateLimiter::clear('media-destroy');
        RateLimiter::clear('test-seed');
        RateLimiter::clear('project-create');
        RateLimiter::clear('media-upload');
        RateLimiter::clear('clip-review-list');
        RateLimiter::clear('clip-review-show');
        RateLimiter::clear('clip-review-update');
        RateLimiter::clear('image-generate');
        RateLimiter::clear('image-list');
        RateLimiter::clear('image-show');
        RateLimiter::clear('social-connect');
        RateLimiter::clear('social-list');
        RateLimiter::clear('social-disconnect');
        RateLimiter::clear('social-publish');
        RateLimiter::clear('social-campaign-publish');
        RateLimiter::clear('social-campaign-retry');
        RateLimiter::clear('social-campaign-cancel');

        parent::tearDown();
    }
}
