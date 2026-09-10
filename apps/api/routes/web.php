<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'AiClip API',
        'version' => 'v1',
        'health' => '/api/v1/health',
    ]);
});
