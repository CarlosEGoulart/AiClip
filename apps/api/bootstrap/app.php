<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', StartSession::class);
        $middleware->statefulApi();
        $middleware->encryptCookies(except: ['XSRF-TOKEN', 'laravel_session']);
        $middleware->validateCsrfTokens(except: [
            'api/v1/projects',
            'api/v1/projects/*',
            'api/v1/social/*/connect',
            'api/v1/social/connections/*',
            'api/v1/social/publish',
            'api/v1/social/publish/campaign',
            'api/v1/social/publish/campaigns/*/cancel',
            'api/v1/social/publish/campaigns/*/retry',
            'api/v1/auth/register',
            'api/v1/auth/login',
            'api/v1/auth/logout',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
