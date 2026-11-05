<?php

namespace Tests;

use App\Services\ProcessMediaAction;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Ensure database tables exist for all tests in this class.
        // RefreshDatabase::setUp() is shadowed by TestCase::setUp(), so run
        // migrations here as a fallback. Runs once per test class, not per
        // test, to avoid exhausting DB connections.
        if (!Schema::hasTable('users')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Mock ProcessMediaAction to prevent actual worker invocation during testing
        $actionMock = Mockery::mock(ProcessMediaAction::class);
        $actionMock->shouldReceive('probe')
            ->andReturn([
                'status' => 'success',
                'probe' => ['duration_ms' => 1000],
            ]);
        app()->instance(ProcessMediaAction::class, $actionMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
