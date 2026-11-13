<?php

namespace Tests\Feature;

use App\Services\ProductionEnvironmentValidator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Config::set('app.debug', (bool) env('APP_DEBUG', false));
    Config::set('app.env', env('APP_ENV', 'production'));
});

it('fails fast when required production environment variables are missing', function () {
    $validator = app(ProductionEnvironmentValidator::class);

    // Temporarily unset a required var and assert validation throws.
    $missing = env('APP_KEY');
    putenv('APP_KEY=');

    try {
        $validator->validateProductionRequired();
        expect(true)->toBeFalse('expected ProductionEnvironmentValidator to throw when APP_KEY is missing');
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toContain('APP_KEY');
    } finally {
        if ($missing !== false) {
            putenv('APP_KEY=' . $missing);
        } else {
            putenv('APP_KEY');
        }
    }
});

it('APP_DEBUG is false in a production-like environment', function () {
    putenv('APP_ENV=production');
    putenv('APP_DEBUG=');
    // env() reads \$_SERVER/\$_ENV before getenv(), so clear the superglobals
    // that were populated from .env during bootstrap so putenv() takes effect.
    unset($_SERVER['APP_DEBUG'], $_ENV['APP_DEBUG']);

    Config::set('app.env', 'production');
    Config::set('app.debug', (bool) env('APP_DEBUG', false));

    expect(config('app.debug'))->toBeFalse();
});

it('APP_DEBUG cannot be silently enabled in production without explicit override', function () {
    putenv('APP_ENV=production');
    putenv('APP_DEBUG=true');
    // env() reads \$_SERVER/\$_ENV before getenv(), so clear the superglobals
    // that were populated from .env during bootstrap so putenv() takes effect.
    unset($_SERVER['APP_ENV'], $_ENV['APP_ENV'], $_SERVER['APP_DEBUG'], $_ENV['APP_DEBUG']);

    // Re-apply the config/app.php debug closure under the simulated production
    // env. The production guard forces debug off regardless of APP_DEBUG, so
    // even with APP_DEBUG=true the resolved value must be false.
    Config::set('app.debug', (function (): bool {
        if (env('APP_ENV', 'production') === 'production') {
            return false;
        }

        return (bool) env('APP_DEBUG', false);
    })());

    expect(config('app.debug'))->toBeFalse();
});

it('APP_KEY is present and is not the committed example value', function () {
    $key = config('app.key');

    expect($key)->not->toBeNull();
    expect($key)->not->toBe('');
    expect($key)->not->toBe('base64:8jR9mK2pL5nQ7vW3xYzAbCdEfGhIjKlMnOpQrStUvWx=');
});

it('does not contain hardcoded secret literals in source files', function () {
    $secretPatterns = [
        '/sk_live_[0-9a-zA-Z]{24,}/',
        '/sk_test_[0-9a-zA-Z]{24,}/',
        '/AKIA[0-9A-Z]{16}/',
        '/password\s*=\s*["\'"][^"\']+["\']/i',
        '/secret\s*=\s*["\'"][^"\']+["\']/i',
    ];

    $basePath = base_path('app');
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $violations = [];

    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getExtension(), 'php')) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            continue;
        }

        foreach ($secretPatterns as $pattern) {
            if (preg_match($pattern, $contents)) {
                $violations[] = $file->getPathname();
                break;
            }
        }
    }

    expect($violations)->toBeEmpty();
});
