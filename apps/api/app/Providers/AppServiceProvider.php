<?php

namespace App\Providers;

use App\Contracts\ImageGenerationProvider;
use App\Services\FakeImageGenerationProvider;
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
    }
}
