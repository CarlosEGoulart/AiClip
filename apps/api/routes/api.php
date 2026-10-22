<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClipReviewController;
use App\Http\Controllers\Api\V1\ImageController;
use App\Http\Controllers\Api\V1\MediaAssetController;
use App\Http\Controllers\Api\V1\ProjectController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
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

    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Project management routes
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store'])
            ->middleware('throttle:project-create');
        Route::get('/projects/{project}', [ProjectController::class, 'show']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);

        // Clip Review routes
        Route::get('/projects/{project}/clip-reviews', [ClipReviewController::class, 'index'])
            ->middleware('throttle:clip-review-list');
        Route::get('/clip-reviews/{clipReview}', [ClipReviewController::class, 'show'])
            ->middleware('throttle:clip-review-show');
        Route::patch('/clip-reviews/{clipReview}/status', [ClipReviewController::class, 'updateStatus'])
            ->middleware('throttle:clip-review-update');
        Route::post('/clip-reviews/{clipReview}/feedback', [ClipReviewController::class, 'storeFeedback'])
            ->middleware('throttle:clip-review-update');

        // Test-seeding route: registered only in the `testing` environment.
        // Creates a MediaAsset + rendered DerivedAsset + pending ClipReview
        // for E2E fixtures. Never exposed outside testing.
        if (app()->environment('testing')) {
            Route::post('/test/clip-reviews/seed', [ClipReviewController::class, 'seed']);
        }

        // Media routes
        Route::post('/projects/{project}/media/upload', [MediaAssetController::class, 'upload'])
            ->middleware('throttle:media-upload');
        Route::get('/projects/{project}/media', [MediaAssetController::class, 'index']);
        Route::delete('/media/{media}', [MediaAssetController::class, 'destroy']);

        // Image Studio routes (M8 Slice 1): generation enqueues a queue job;
        // list/show are user-scoped reads.
        Route::post('/images/generate', [ImageController::class, 'generate'])
            ->middleware('throttle:image-generate');
        Route::get('/images', [ImageController::class, 'index'])
            ->middleware('throttle:image-list');
        Route::get('/images/{image}', [ImageController::class, 'show'])
            ->middleware('throttle:image-show');
    });

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
