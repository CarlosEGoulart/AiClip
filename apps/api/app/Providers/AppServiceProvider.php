<?php

namespace App\Providers;

use App\Contracts\ClipRankingProvider;
use App\Services\FakeRankingProvider;
use App\Services\WorkerRankingProvider;
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
        // Single ClipRankingProvider binding: the CI/testing context uses the
        // deterministic PHP fake; production/default contexts bind the thin
        // adapter that delegates through the single ProcessMediaAction::
        // rankClips path. rankClips itself never resolves this binding.
        $this->app->bind(
            ClipRankingProvider::class,
            $this->app->environment('testing') ? FakeRankingProvider::class : WorkerRankingProvider::class,
        );
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
    }
}
