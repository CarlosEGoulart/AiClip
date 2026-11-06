<?php

namespace App\Services;

/**
 * Validates that all required production environment variables are present
 * before the application boots. Missing required variables cause a fail-fast
 * RuntimeException rather than silently falling back to insecure defaults.
 */
class ProductionEnvironmentValidator
{
    /**
     * Environment variables required in production (APP_ENV=production).
     * These must be set explicitly; there are no insecure defaults.
     *
     * @var list<string>
     */
    protected array $required = [
        'APP_KEY',
        'APP_ENV',
        'APP_DEBUG',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
    ];

    /**
     * The committed example APP_KEY value that must not be used in production.
     */
    protected string $exampleAppKey = 'base64:8jR9mK2pL5nQ7vW3xYzAbCdEfGhIjKlMnOpQrStUvWx=';

    /**
     * Validate that all required production environment variables are present
     * and non-default. Throws \RuntimeException on the first missing or
     * insecure value.
     */
    public function validateProductionRequired(): void
    {
        foreach ($this->required as $var) {
            $value = env($var);

            if ($value === false || $value === '' || trim((string) $value) === '') {
                throw new \RuntimeException(
                    "Missing required production environment variable: {$var}. "
                    .'Set it in your deployment environment before booting.'
                );
            }
        }

        $appKey = env('APP_KEY');
        if ($appKey === $this->exampleAppKey) {
            throw new \RuntimeException(
                'APP_KEY is set to the committed example value. '
                .'Generate a per-deployment key (php artisan key:generate) before booting production.'
            );
        }
    }

    /**
     * Validate production-specific safety guards.
     *
     * @return void
     */
    public function validateProductionSafety(): void
    {
        if (env('APP_ENV') === 'production' && filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException(
                'APP_DEBUG must not be enabled in production. Set APP_DEBUG=false.'
            );
        }
    }
}
