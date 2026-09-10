<?php

use App\Http\Controllers\Api\V1\AuthController;
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

    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('web');
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('web');

    Route::middleware(['web', 'auth:sanctum'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
    });
});
