<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', [AddQueuedCookiesToResponse::class, StartSession::class]);
        $middleware->statefulApi();
        $middleware->encryptCookies(except: ['XSRF-TOKEN', 'laravel_session']);
        $middleware->validateCsrfTokens(except: [
            'api/v1/projects',
            'api/v1/projects/*',
            'api/v1/projects/*/media/upload',
            'api/v1/social/*/connect',
            'api/v1/social/connections/*',
            'api/v1/social/publish',
            'api/v1/social/publish/campaign',
            'api/v1/social/publish/campaigns/*/cancel',
            'api/v1/social/publish/campaigns/*/retry',
            'api/v1/auth/register',
            'api/v1/auth/login',
            'api/v1/auth/logout',
            'api/v1/social/*/callback',
            'api/v1/media/*',
            'api/v1/images/generate',
            'api/v1/images/download',
            'api/v1/clip-reviews/*/status',
            'api/v1/clip-reviews/*/feedback',
            'api/v1/test/clip-reviews/seed',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
