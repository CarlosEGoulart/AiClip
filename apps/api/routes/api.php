<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClipReviewController;
use App\Http\Controllers\Api\V1\ImageController;
use App\Http\Controllers\Api\V1\MediaAssetController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\SocialConnectionController;
use App\Http\Controllers\Api\V1\SocialPublishCampaignController;
use App\Http\Controllers\Api\V1\SocialPublishController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::prefix('v1')->group(function () {
    // Health and readiness are unauthenticated, machine-readable endpoints
    // used by operators and monitoring. They return only safe, aggregated
    // status — never stack traces, storage internals, or credentials.
    Route::get('/health', function () {
        try {
            DB::select('SELECT 1');

            return response()->json([
                'status' => 'ok',
                'database' => 'connected',
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'database' => 'disconnected',
            ], 503);
        }
    });

    Route::get('/readiness', function () {
        $database = 'ok';
        try {
            DB::select('SELECT 1');
        } catch (Exception $e) {
            $database = 'disconnected';
        }

        $storage = 'ok';
        try {
            Storage::disk(config('media.disk', 'media'))->getDriver();
        } catch (Exception $e) {
            $storage = 'unreachable';
        }

        $queue = 'ok';
        try {
            DB::table('jobs')->limit(1)->exists();
        } catch (Exception $e) {
            $queue = 'unreachable';
        }

        $degraded = $database !== 'ok' || $storage !== 'ok' || $queue !== 'ok';

        return response()->json([
            'status' => $degraded ? 'degraded' : 'ok',
            'dependencies' => [
                'database' => $database,
                'storage' => $storage,
                'queue' => $queue,
            ],
        ], $degraded ? 503 : 200);
    });

    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:auth-register', VerifyCsrfToken::class);
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->name('login')
        ->middleware('throttle:auth-login', VerifyCsrfToken::class);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])
            ->middleware('throttle:auth-logout', VerifyCsrfToken::class);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Project management routes
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store'])
            ->middleware('throttle:project-create', VerifyCsrfToken::class);
        Route::get('/projects/{project}', [ProjectController::class, 'show']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])
            ->middleware('throttle:project-destroy', VerifyCsrfToken::class);

        // Clip Review routes
        Route::get('/projects/{project}/clip-reviews', [ClipReviewController::class, 'index'])
            ->middleware('throttle:clip-review-list');
        Route::get('/clip-reviews/{clipReview}', [ClipReviewController::class, 'show'])
            ->middleware('throttle:clip-review-show');
        Route::patch('/clip-reviews/{clipReview}/status', [ClipReviewController::class, 'updateStatus'])
            ->middleware('throttle:clip-review-update', VerifyCsrfToken::class);
        Route::post('/clip-reviews/{clipReview}/feedback', [ClipReviewController::class, 'storeFeedback'])
            ->middleware('throttle:clip-review-update', VerifyCsrfToken::class);

        // Test-seeding route: registered only in the `testing` environment.
        // Creates a MediaAsset + rendered DerivedAsset + pending ClipReview
        // for E2E fixtures. Never exposed outside testing.
        if (app()->environment('testing')) {
            Route::post('/test/clip-reviews/seed', [ClipReviewController::class, 'seed'])
                ->middleware('throttle:test-seed', VerifyCsrfToken::class);
        }

        // Media routes
        Route::post('/projects/{project}/media/upload', [MediaAssetController::class, 'upload'])
            ->middleware('throttle:media-upload', VerifyCsrfToken::class);
        Route::get('/projects/{project}/media', [MediaAssetController::class, 'index']);
        Route::delete('/media/{media}', [MediaAssetController::class, 'destroy'])
            ->middleware('throttle:media-destroy', VerifyCsrfToken::class);

        // Image Studio routes (M8 Slice 1): generation enqueues a queue job;
        // list/show are user-scoped reads.
        Route::post('/images/generate', [ImageController::class, 'generate'])
            ->middleware('throttle:image-generate', VerifyCsrfToken::class);
        Route::get('/images', [ImageController::class, 'index'])
            ->middleware('throttle:image-list');
        Route::get('/images/{image}', [ImageController::class, 'show'])
            ->middleware('throttle:image-show');

        // Social Connection routes (authenticated + CSRF for state-changing ops)
        Route::get('/social/connections', [SocialConnectionController::class, 'index'])
            ->middleware('throttle:social-list');
        Route::post('/social/{platform}/connect', [SocialConnectionController::class, 'connect'])
            ->middleware(['throttle:social-connect', VerifyCsrfToken::class]);
        Route::delete('/social/connections/{connection}', [SocialConnectionController::class, 'disconnect'])
            ->middleware(['throttle:social-disconnect', VerifyCsrfToken::class]);
        Route::get('/social/connections/{connection}/status', [SocialConnectionController::class, 'status'])
            ->middleware('throttle:social-list');

        // Social Publish routes (authenticated + CSRF for state-changing ops)
        Route::post('/social/publish', [SocialPublishController::class, 'store'])
            ->middleware(['throttle:social-publish', VerifyCsrfToken::class]);
        Route::get('/social/publishes', [SocialPublishController::class, 'index'])
            ->middleware('throttle:social-list');
        Route::get('/social/publishes/{publish}', [SocialPublishController::class, 'show'])
            ->middleware('throttle:social-list');

        // Social Publish Campaign routes (authenticated + CSRF for state-changing ops)
        Route::post('/social/publish/campaign', [SocialPublishCampaignController::class, 'store'])
            ->middleware(['throttle:social-campaign-publish', VerifyCsrfToken::class]);
        Route::get('/social/publish/campaigns', [SocialPublishCampaignController::class, 'index'])
            ->middleware('throttle:social-list');
        Route::get('/social/publish/campaigns/{campaign}', [SocialPublishCampaignController::class, 'show'])
            ->middleware('throttle:social-list');
        Route::post('/social/publish/campaigns/{campaign}/cancel', [SocialPublishCampaignController::class, 'cancel'])
            ->middleware(['throttle:social-campaign-cancel', VerifyCsrfToken::class]);
        Route::post('/social/publish/campaigns/{campaign}/retry', [SocialPublishCampaignController::class, 'retry'])
            ->middleware(['throttle:social-campaign-retry', VerifyCsrfToken::class]);
    });

    // Callback must be accessible without authentication (OAuth redirect from provider)
    Route::get('/social/{platform}/callback', [SocialConnectionController::class, 'callback'])
        ->middleware('throttle:social-connect');

    // Clip review video playback is reachable either through a time-limited
    // signed URL (clip-review.video, issued to owners via the resource) or
    // through an authenticated session; the controller enforces both paths.
    Route::get('/clip-reviews/{clipReview}/video', [ClipReviewController::class, 'video'])
        ->name('clip-review.video');

    // Image Studio download (M8 Slice 2) mirrors the clip review video
    // dual gate: a time-limited signed URL (image.download, issued only
    // for generated images via the resource) or an authenticated owner
    // session; the controller enforces both paths and the artifact guard.
    Route::get('/images/{image}/download', [ImageController::class, 'download'])
        ->name('image.download');
});
