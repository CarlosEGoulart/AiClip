<?php

namespace App\Providers;

use App\Contracts\ImageGenerationProvider;
use App\Contracts\MediaProcessingContract;
use App\Contracts\SocialConnectionProvider;
use App\Contracts\SocialPublisher;
use App\Services\FakeImageGenerationProvider;
use App\Services\FakeInstagramPublisher;
use App\Services\FakeInstagramProvider;
use App\Services\FakeTikTokPublisher;
use App\Services\FakeTikTokProvider;
use App\Services\FakeYouTubePublisher;
use App\Services\FakeYouTubeProvider;
use App\Services\InstagramPublisher;
use App\Services\ProcessMediaAction;
use App\Services\ProductionEnvironmentValidator;
use App\Services\SocialConnectionManager;
use App\Services\TikTokPublisher;
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

        // Social connection providers - register fake providers in all environments.
        // Real provider implementations replace these bindings in a future slice.
        $this->app->bind(SocialConnectionProvider::class.':youtube', FakeYouTubeProvider::class);
        $this->app->bind(SocialConnectionProvider::class.':instagram', FakeInstagramProvider::class);
        $this->app->bind(SocialConnectionProvider::class.':tiktok', FakeTikTokProvider::class);

        // Social publisher - bind fake publishers by default (safe for tests).
        // Real publishers are bound in environment-specific providers or
        // config, never here, to avoid test contamination.
        $this->app->bind(SocialPublisher::class.':youtube', FakeYouTubePublisher::class);
        $this->app->bind(SocialPublisher::class.':instagram', FakeInstagramPublisher::class);
        $this->app->bind(SocialPublisher::class.':tiktok', FakeTikTokPublisher::class);

        $this->app->singleton(SocialConnectionManager::class);

        // In the testing/e2e environment, the Python media worker (FFprobe,
        // FFmpeg, PySceneDetect, faster-whisper) is not available. Bind a stub
        // ProcessMediaAction so the ProcessMediaAsset job runs inline (sync
        // queue) without invoking the absent worker. Production uses the real
        // ProcessMediaAction which shells out to the Python worker CLI.
        if ($this->app->environment('testing')) {
            $this->app->bind(ProcessMediaAction::class, function () {
                return new class extends ProcessMediaAction {
                    public function probe(MediaProcessingContract $contract): array
                    {
                        return [
                            'status' => 'success',
                            'probe' => [
                                'video_codec' => 'h264',
                                'audio_codec' => 'aac',
                                'duration_ms' => 30000,
                                'width' => 1920,
                                'height' => 1080,
                            ],
                        ];
                    }

                    public function extractAudio(MediaProcessingContract $contract): array
                    {
                        return ['status' => 'success', 'extraction' => []];
                    }

                    public function transcribe(MediaProcessingContract $contract): array
                    {
                        return [
                            'status' => 'success',
                            'transcription' => [
                                'language' => 'en',
                                'full_text' => '',
                                'segments' => [],
                                'engine' => 'fake',
                                'model' => 'fake',
                            ],
                        ];
                    }

                    public function detectScenes(MediaProcessingContract $contract): array
                    {
                        return [
                            'status' => 'success',
                            'scene_detection' => [
                                'detector' => 'fake',
                                'detector_version' => '1.0.0',
                                'parameters' => [],
                                'scenes' => [],
                            ],
                        ];
                    }

                    public function analyzeClips(MediaProcessingContract $contract): array
                    {
                        return [
                            'status' => 'success',
                            'analysis' => [
                                'algorithm' => 'fake',
                                'algorithm_version' => '1.0.0',
                                'parameters' => [],
                                'candidates' => [],
                            ],
                        ];
                    }

                    public function rankClips(MediaProcessingContract $contract): array
                    {
                        return ['status' => 'success', 'ranking' => []];
                    }

                    public function renderClips(MediaProcessingContract $contract): array
                    {
                        return ['status' => 'success', 'render' => []];
                    }
                };
            });
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fail fast in production if required environment variables are
        // missing or insecure. Never silently fall back to defaults.
        if ($this->app->environment('production') && !$this->app->runningInConsole()) {
            app(ProductionEnvironmentValidator::class)->validateProductionRequired();
            app(ProductionEnvironmentValidator::class)->validateProductionSafety();
        }

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

        RateLimiter::for('social-campaign-publish', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('social-campaign-retry', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('social-campaign-cancel', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // Authentication rate limiters (apply outside auth:sanctum group)
        RateLimiter::for('auth-register', function (Request $request) {
            return Limit::perMinute(app()->environment('testing') ? 60 : 5)->by($request->ip());
        });

        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinute(app()->environment('testing') ? 60 : 10)->by($request->ip());
        });

        RateLimiter::for('auth-logout', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Destructive action rate limiters
        RateLimiter::for('project-destroy', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('media-destroy', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('test-seed', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });
    }
}
