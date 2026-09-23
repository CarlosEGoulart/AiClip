<?php

namespace Tests\Support;

/*
 * Issue #60 disposable-database guard (test-only support).
 *
 * Derives the expected database from configuration instead of hardcoding one
 * name, so the same suite runs locally and in CI. Fails closed unless the
 * effective target is a disposable `aiclip_test*` database and never the
 * `aiclip` development/production database; empty or unknown values refuse.
 */
final class Issue60DbGuard
{
    public static function expectedDatabase(): string
    {
        $name = config('database.connections.pgsql.database');

        if (! is_string($name) || $name === '' || $name === 'aiclip' || ! str_starts_with($name, 'aiclip_test')) {
            throw new \RuntimeException('Refusing #60 destructive tests: unauthorized database target.');
        }

        return $name;
    }
}
