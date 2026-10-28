<?php

namespace App\Providers;

use App\Contracts\ImageGenerationProvider;
use App\Contracts\SocialConnectionProvider;
use App\Contracts\SocialPublisher;
use App\Services\FakeImageGenerationProvider;
use App\Services\FakeInstagramProvider;
use App\Services\FakeTikTokProvider;
use App\Services\FakeYouTubePublisher;
use App\Services\FakeYouTubeProvider;
use App\Services\SocialConnectionManager;
use App\Services\YouTubePublisher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The clip recommendation provider is selected by
        // config('media.clip_ranking_provider') and dispatched through the
        // single ProcessMediaAction::rankClips worker path. No container
        // binding for a PHP-side ranking provider exists.

        // Image generation provider seam: the deterministic fake is the
        // bound implementation of this slice. The model-backed provider
        // (Flux/SDXL via the Python worker CLI) replaces this binding in a
        // future slice without any domain changes.
        $this->app->singleton(ImageGenerationProvider::class, FakeImageGenerationProvider::class);

        // Social connection providers - register fake providers for testing
        if ($this->app->environment('testing')) {
            $this->app->bind(SocialConnectionProvider::class.':youtube', FakeYouTubeProvider::class);
            $this->app->bind(SocialConnectionProvider::class.':instagram', FakeInstagramProvider::class);
            $this->app->bind(SocialConnectionProvider::class.':tiktok', FakeTikTokProvider::class);
        }

        // Social publisher - YouTube publisher bound by platform key
        $this->app->bind(SocialPublisher::class.':youtube', YouTubePublisher::class);

        // Override with fake publisher in testing environment
        if ($this->app->environment('testing')) {
            $this->app->bind(SocialPublisher::class.':youtube', FakeYouTubePublisher::class);
        }

        $this->app->singleton(SocialConnectionManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('project-create', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('media-upload', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('clip-review-list', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('clip-review-show', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('clip-review-update', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('image-generate', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('image-list', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('image-show', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Social connection rate limiters
        RateLimiter::for('social-connect', function (Request $request) {
            return Limit::perMinute(config('social.rate_limits.connect', 20))->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('social-list', function (Request $request) {
            return Limit::perMinute(config('social.rate_limits.list', 60))->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('social-disconnect', function (Request $request) {
            return Limit::perMinute(config('social.rate_limits.disconnect', 30))->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('social-publish', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });
    }
}
