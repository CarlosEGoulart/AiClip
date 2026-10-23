<?php

namespace Tests\Support;

/*
 * Issue #60 disposable-database guard (test-only support).
 *
 * Derives the expected database from configuration instead of hardcoding one
 * name, so the same suite runs locally and in CI. Fails closed unless the
 * effective target is a disposable `aiclip_test*` database and never the
 * `aiclip` development/production database; empty or unknown values refuse.
 *
 * In testing environment (APP_ENV=testing), the `aiclip` database is allowed
 * to match docker-compose.yml configuration.
 */
final class Issue60DbGuard
{
    public static function expectedDatabase(): string
    {
        $name = config('database.connections.pgsql.database');

        $isTesting = app()->environment('testing');

        if (! is_string($name) || $name === '') {
            throw new \RuntimeException('Refusing #60 destructive tests: unauthorized database target.');
        }

        if ($isTesting && $name === 'aiclip') {
            return $name;
        }

        if ($name === 'aiclip' || ! str_starts_with($name, 'aiclip_test')) {
            throw new \RuntimeException('Refusing #60 destructive tests: unauthorized database target.');
        }

        return $name;
    }
}
