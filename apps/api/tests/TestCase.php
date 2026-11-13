<?php

namespace Tests;

use App\Services\ProcessMediaAction;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    protected static bool $migrationsRan = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure database tables exist for all tests. RefreshDatabase::setUp()
        // is shadowed by TestCase::setUp() (class method takes precedence over
        // trait method), so run migrations here as a fallback. migrate is
        // idempotent: it is a no-op when all migrations have already run.
        // Runs only once per process to avoid exhausting DB connections.
        if (!self::$migrationsRan) {
            Artisan::call('migrate', ['--force' => true]);
            self::$migrationsRan = true;
        }

        // Mock ProcessMediaAction to prevent actual worker invocation during testing
        $actionMock = Mockery::mock(ProcessMediaAction::class);
        $actionMock->shouldReceive('probe')
            ->andReturn([
                'status' => 'success',
                'probe' => ['duration_ms' => 1000],
            ]);
        app()->instance(ProcessMediaAction::class, $actionMock);
    }

    public static function resetMigrationsRan(): void
    {
        self::$migrationsRan = false;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
